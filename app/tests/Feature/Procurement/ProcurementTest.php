<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentNumbers;
use App\Domain\Procurement\QuoteService;
use App\Domain\Procurement\RequirementService;
use App\Domain\Procurement\SaudaService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use App\Models\Procurement\PaddyRequirement;
use App\Models\Procurement\Quote;
use App\Models\Procurement\Sauda;
use App\Models\Procurement\SaudaEvent;
use App\Support\Values\Weight;
use Illuminate\Support\Facades\DB;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('PRC'));
    $this->f->as($this->f->owner);
});

it('numbers documents per entity, series and financial year without gaps', function () {
    $a = app(SaudaService::class)->create(['party_id' => $this->f->seller->id, 'product_id' => $this->f->paddy->id, 'quantity_kg' => '1000', 'rate_paise_per_maund' => 200000]);
    try {
        DB::transaction(function () {
            app(SaudaService::class)->create(['party_id' => $this->f->seller->id, 'product_id' => $this->f->paddy->id, 'quantity_kg' => '1000', 'rate_paise_per_maund' => 200000]);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
    $b = app(SaudaService::class)->create(['party_id' => $this->f->seller->id, 'product_id' => $this->f->paddy->id, 'quantity_kg' => '1000', 'rate_paise_per_maund' => 200000]);

    $fy = DocumentNumbers::financialYear(now());
    expect($a->number)->toBe("PRC/P/{$fy}/0001")->and($b->number)->toBe("PRC/P/{$fy}/0002");
});

it('broadcasts a requirement to brokers and farmers only, once each', function () {
    $customer = Party::query()->create(['code' => 'C1', 'name' => 'Customer', 'is_customer' => true]);
    $req = app(RequirementService::class)->create([
        'product_id' => $this->f->paddy->id, 'quantity_kg' => '500000', 'target_rate_paise_per_maund' => 260000,
        'quality_spec' => ['moisture_max_bp' => 1700],
    ]);

    $sent = app(RequirementService::class)->broadcast($req, [$this->f->seller->id, $this->f->broker->id, $customer->id]);
    $again = app(RequirementService::class)->broadcast($req->fresh(), [$this->f->seller->id]);

    expect($sent)->toBe(2)->and($again)->toBe(0)
        ->and($req->fresh()->status)->toBe(PaddyRequirement::STATUS_OPEN)
        ->and($req->recipients()->count())->toBe(2);
});

describe('negotiation', function () {
    beforeEach(function () {
        $this->quote = app(QuoteService::class)->submit([
            'party_id' => $this->f->seller->id, 'broker_party_id' => $this->f->broker->id, 'product_id' => $this->f->paddy->id,
            'quantity_kg' => '50000', 'rate_paise_per_maund' => 270000,
        ], Quote::BY_SELLER, 'Opening offer');
    });

    it('keeps every offer and counter-offer as a revision', function () {
        $q = app(QuoteService::class)->counter($this->quote, '50000', 255000, Quote::BY_MILL, 'Too high');
        $q = app(QuoteService::class)->counter($q, '45000', 262000, Quote::BY_SELLER);

        expect($q->current_revision)->toBe(3)
            ->and($q->revisions()->pluck('rate_paise_per_maund')->all())->toBe([270000, 255000, 262000])
            ->and($q->revisions()->pluck('offered_by')->all())->toBe(['seller', 'mill', 'seller']);
    });

    it('does not let a side counter or accept its own offer', function () {
        expect(fn () => app(QuoteService::class)->counter($this->quote, '1', 1, Quote::BY_SELLER))->toThrow(BusinessRuleViolation::class)
            ->and(fn () => app(QuoteService::class)->accept($this->quote, Quote::BY_SELLER))->toThrow(BusinessRuleViolation::class, 'own offer');
    });

    it('creates the sauda from the accepted terms and sends it for approval', function () {
        $q = app(QuoteService::class)->counter($this->quote, '50000', 262500, Quote::BY_MILL);
        $sauda = app(QuoteService::class)->accept($q, Quote::BY_SELLER);

        expect($sauda->status)->toBe(Sauda::STATUS_PENDING)
            ->and($sauda->rate_paise_per_maund)->toBe(262500)
            ->and($sauda->quantity_kg)->toBe('50000.000')
            ->and($sauda->broker_party_id)->toBe($this->f->broker->id)
            ->and($q->fresh()->status)->toBe(Quote::STATUS_ACCEPTED)
            ->and($q->fresh()->sauda_id)->toBe($sauda->id)
            ->and(fn () => app(QuoteService::class)->counter($q->fresh(), '1', 1, Quote::BY_SELLER))->toThrow(BusinessRuleViolation::class, 'accepted');
    });
});

describe('sauda lifecycle', function () {
    beforeEach(function () {
        $this->sauda = app(SaudaService::class)->submit(app(SaudaService::class)->create([
            'party_id' => $this->f->seller->id, 'broker_party_id' => $this->f->broker->id, 'product_id' => $this->f->paddy->id,
            'quantity_kg' => '20000', 'tolerance_bp' => 500, 'rate_paise_per_maund' => 250000,
            'delivery_from' => now()->toDateString(), 'delivery_to' => now()->addDays(10)->toDateString(),
        ]));
    });

    it('needs a different person with deal.approve to approve', function () {
        expect(fn () => app(SaudaService::class)->approve($this->sauda))->toThrow(BusinessRuleViolation::class, 'maker-checker');

        $this->f->as(Make::account($this->f->entity, 'EXECUTIVE'));
        expect(fn () => app(SaudaService::class)->approve($this->sauda))->toThrow(BusinessRuleViolation::class, 'deal.approve');

        $this->f->as($this->f->owner2);
        $approved = app(SaudaService::class)->approve($this->sauda, 'ok');
        expect($approved->status)->toBe(Sauda::STATUS_CONFIRMED)
            ->and($approved->approved_by)->toBe($this->f->owner2->id);
    });

    it('freezes the commercial terms once approved', function () {
        $this->f->as($this->f->owner2);
        $s = app(SaudaService::class)->approve($this->sauda);

        $s->rate_paise_per_maund = 1;
        expect(fn () => $s->save())->toThrow(LogicException::class, 'cannot be changed');
    });

    it('timestamps every transition with the actor', function () {
        $this->f->as($this->f->owner2);
        app(SaudaService::class)->approve($this->sauda);
        $events = SaudaEvent::query()->where('sauda_id', $this->sauda->id)->orderBy('occurred_at')->get();

        expect($events->pluck('event')->all())->toBe(['created', 'submitted', 'approved'])
            ->and($events->last()->actor_login_account_id)->toBe($this->f->owner2->id)
            ->and(fn () => $events->first()->update(['event' => 'x']))->toThrow(LogicException::class);
    });

    it('books deliveries, completes within tolerance and flags over-delivery', function () {
        $this->f->as($this->f->owner2);
        $s = app(SaudaService::class)->approve($this->sauda);
        $entry = new GateEntry(['registration_no' => 'X', 'direction' => 'inbound', 'purpose' => 'paddy_purchase', 'arrived_at' => now()]);
        $entry->id = (string) Str::uuid();

        $s = app(SaudaService::class)->recordDelivery($s, $entry, Weight::kg('12000'));
        expect($s->status)->toBe(Sauda::STATUS_IN_DELIVERY);

        // 19,000 ≥ 20,000 − 5 % → completed
        $s = app(SaudaService::class)->recordDelivery($s, $entry, Weight::kg('7000'));
        expect($s->status)->toBe(Sauda::STATUS_COMPLETED)->and($s->delivered_kg)->toBe('19000.000')
            ->and(fn () => app(SaudaService::class)->recordDelivery($s, $entry, Weight::kg('1')))->toThrow(BusinessRuleViolation::class);
    });

    it('records over-delivery truthfully and flags it', function () {
        $this->f->as($this->f->owner2);
        $s = app(SaudaService::class)->approve($this->sauda);
        $entry = new GateEntry(['registration_no' => 'X', 'direction' => 'inbound', 'purpose' => 'paddy_purchase', 'arrived_at' => now()]);
        $entry->id = (string) Str::uuid();

        $s = app(SaudaService::class)->recordDelivery($s, $entry, Weight::kg('22000'));
        $delivery = SaudaEvent::query()->where('sauda_id', $s->id)->where('event', 'delivery')->sole();

        expect($s->delivered_kg)->toBe('22000.000')->and($delivery->data['over_tolerance'])->toBeTrue();
    });

    it('expires deals whose delivery period ended and records what was left', function () {
        $this->f->as($this->f->owner2);
        app(SaudaService::class)->approve($this->sauda);
        $this->travel(12)->days();

        expect(app(SaudaService::class)->expireOverdue())->toBe(1);
        $s = $this->sauda->fresh();
        expect($s->status)->toBe(Sauda::STATUS_EXPIRED)
            ->and(SaudaEvent::query()->where('sauda_id', $s->id)->where('event', 'expired')->sole()->data['remaining_kg'])->toBe('20000.000');
    });

    it('shows brokers only the deals they brokered', function () {
        $brokerAccount = Make::account($this->f->entity, 'BROKER');
        Make::system(fn () => $this->f->broker->forceFill(['login_account_id' => $brokerAccount->id])->save());
        $this->f->as($this->f->owner);
        $other = app(SaudaService::class)->create(['party_id' => $this->f->seller->id, 'product_id' => $this->f->paddy->id, 'quantity_kg' => '1000', 'rate_paise_per_maund' => 1]);

        $this->f->as($brokerAccount);
        $visible = Sauda::query()->visibleTo($brokerAccount)->pluck('id')->all();

        expect($visible)->toBe([$this->sauda->id])->not->toContain($other->id);
    });
});
