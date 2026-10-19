<?php

declare(strict_types=1);

use App\Domain\Approvals\Actions\ManualWeighmentOverride;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Gate\GateService;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Gate\ScaleCertificate;
use App\Models\Gate\Weighment;
use App\Models\Procurement\Sauda;
use App\Models\Procurement\SaudaEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('GTE'));
    $this->sauda = $this->f->confirmedSauda('100000');
    // Gate staff: a supervisor can do every gate step (entry, weigh, lab test + decision).
    $this->sup = Make::account($this->f->entity, 'SUPERVISOR');
    $this->f->as($this->sup);
    $this->gate = app(GateService::class);
});

const PHOTO = 'a000000000000000000000000000000000000000000000000000000000000000';

function weigh(object $t, GateEntry $e, string $kind, string $kg, ?array $readings = null, ?string $photo = PHOTO): Weighment
{
    return $t->gate->recordWeighment($e, $kind, $kg, $t->f->scale, $readings ?? GateFixtures::readings($kg), $photo);
}

/** Inbound paddy, start to finish. */
function fullInbound(object $t, string $gross = '25480.000', string $tare = '10230.000', int $moistureBp = 1850): GateEntry
{
    $e = $t->gate->arrive(['registration_no' => 'mna 21 1234', 'sauda_id' => $t->sauda->id, 'bag_count' => 400]);
    weigh($t, $e, 'gross', $gross);
    $t->gate->recordLabTest($e, ['moisture_bp' => $moistureBp, 'foreign_matter_bp' => 350, 'grade' => 'A']);
    $t->gate->decideLab($e, true);
    $t->gate->markUnloaded($e);
    weigh($t, $e, 'tare', $tare);
    $t->gate->issueSlip($e);
    $t->gate->exit($e);

    return $e->fresh();
}

it('runs the inbound paddy flow and books the payable weight on the sauda', function () {
    $e = fullInbound($this);

    // net 15,250; moisture 18.5 % → 1.5 % = 228.75; bags 400 × 0.6 = 240 → deduction 468.75
    expect($e->status)->toBe('exited')
        ->and($e->number)->toStartWith('GTE/G/')
        ->and($e->party_id)->toBe($this->f->seller->id)
        ->and($e->gross_kg)->toBe('25480.000')
        ->and($e->tare_kg)->toBe('10230.000')
        ->and($e->net_kg)->toBe('15250.000')
        ->and($e->deduction_kg)->toBe('468.750')
        ->and($e->payable_kg)->toBe('14781.250')
        ->and($e->rate_cut_paise_per_maund)->toBe(400) // foreign matter 3.5 % → 1 full point × Rs 4
        ->and($e->events()->pluck('event')->all())
        ->toBe(['arrived', 'gross_weighed', 'lab_accepted', 'unloaded', 'tare_weighed', 'slip_issued', 'exited']);

    $sauda = $this->sauda->fresh();
    expect($sauda->delivered_kg)->toBe('14781.250')
        ->and($sauda->status)->toBe(Sauda::STATUS_IN_DELIVERY)
        ->and(SaudaEvent::query()->where('sauda_id', $sauda->id)->where('event', 'delivery')->sole()->data['gate_entry_id'])->toBe($e->id);
});

it('enforces the order of steps on screens', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA211234']);

    expect(fn () => weigh($this, $e, 'tare', '10000'))->toThrow(BusinessRuleViolation::class, 'before')
        ->and(fn () => $this->gate->markUnloaded($e))->toThrow(BusinessRuleViolation::class, 'lab')
        ->and(fn () => $this->gate->exit($e))->toThrow(BusinessRuleViolation::class, 'slip');
});

it('lets a rejected lot leave without unloading and books nothing', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA211234', 'sauda_id' => $this->sauda->id]);
    weigh($this, $e, 'gross', '25000');
    $this->gate->recordLabTest($e, ['moisture_bp' => 2400]);
    $this->gate->decideLab($e, false, 'Too wet');
    $this->gate->exit($e);

    expect($e->fresh()->status)->toBe('exited')->and($this->sauda->fresh()->delivered_kg)->toBe('0.000');
});

it('requires a note to accept a lot above a reject limit', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA211234', 'sauda_id' => $this->sauda->id]);
    weigh($this, $e, 'gross', '25000');
    $this->gate->recordLabTest($e, ['moisture_bp' => 2300]);

    expect(fn () => $this->gate->decideLab($e, true))->toThrow(BusinessRuleViolation::class, 'reject limit');
    $event = $this->gate->decideLab($e, true, 'Owner agreed to dry it, extra deduction negotiated');
    expect($event->data['above_reject_limit'])->toBeTrue();
});

it('splits lab work: technicians test, supervisors decide', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA211234']);
    weigh($this, $e, 'gross', '25000');

    $this->f->as(Make::account($this->f->entity, 'LAB_TECHNICIAN'));
    $this->gate->recordLabTest($e, ['moisture_bp' => 1600]);
    expect(fn () => $this->gate->decideLab($e, true))->toThrow(BusinessRuleViolation::class, 'lab.decide');
});

describe('weighment evidence', function () {
    beforeEach(function () {
        $this->e = $this->gate->arrive(['registration_no' => 'MNA211234']);
    });

    it('flags unstable readings and missing photos but keeps the record', function () {
        $w = weigh($this, $this->e, 'gross', '25000', ['samples' => ['24980', '25030', '25000']], null);

        expect($w->flags)->toContain('stability_unverified', 'no_photo')
            ->and($this->e->fresh()->flags)->toContain('stability_unverified', 'no_photo');
    });

    it('flags a weighbridge without a valid stamping certificate, or refuses when configured', function () {
        ScaleCertificate::query()->update(['valid_until' => now()->subDay()->toDateString()]);
        expect(weigh($this, $this->e, 'gross', '25000')->flags)->toContain('scale_certificate_invalid');

        config(['ricemill.gate.block_uncertified_scale' => true]);
        $other = $this->gate->arrive(['registration_no' => 'MNA211234']);
        expect(fn () => weigh($this, $other, 'gross', '25000'))->toThrow(BusinessRuleViolation::class, 'certificate');
    });

    it('rejects weights above scale capacity', function () {
        expect(fn () => weigh($this, $this->e, 'gross', '70000'))->toThrow(BusinessRuleViolation::class, 'capacity');
    });

    it('keeps re-weighs as new rows that supersede, and uses the latest', function () {
        $first = weigh($this, $this->e, 'gross', '25000');
        $second = weigh($this, $this->e, 'gross', '25100');

        expect($second->supersedes_id)->toBe($first->id)
            ->and($second->flags)->toContain('reweighed')
            ->and($this->e->fresh()->gross_kg)->toBe('25100.000')
            ->and(Weighment::query()->where('gate_entry_id', $this->e->id)->count())->toBe(2);
    });

    it('flags a tare far from the vehicle\'s usual empty weight', function () {
        foreach (['10200', '10250', '10230'] as $tare) {
            fullInbound($this, tare: $tare);
        }
        $e = $this->gate->arrive(['registration_no' => 'MNA211234', 'sauda_id' => $this->sauda->id]);
        weigh($this, $e, 'gross', '25000');
        $this->gate->recordLabTest($e, ['moisture_bp' => 1600]);
        $this->gate->decideLab($e, true);
        $this->gate->markUnloaded($e);
        $w = weigh($this, $e, 'tare', '10800'); // +570 kg: water in the tank?

        expect($w->flags)->toContain('tare_deviation')
            ->and($w->readings['tare_check']['median_kg'])->toBe('10230.000');
    });

    it('cannot be changed or deleted, by model or SQL', function () {
        $w = weigh($this, $this->e, 'gross', '25000');

        expect(fn () => $w->update(['weight_kg' => '1']))->toThrow(LogicException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('weighments')->where('id', $w->id)->update(['weight_kg' => 1])))
            ->toThrow(QueryException::class, 'append-only');
    });
});

describe('manual weight override', function () {
    beforeEach(function () {
        $this->e = $this->gate->arrive(['registration_no' => 'MNA211234']);
        $this->sa = Make::owner($this->f->entity);
    });

    it('is not available to anyone but the Owner or a Super Admin', function () {
        expect(fn () => app(ApprovalService::class)->submit(ManualWeighmentOverride::KEY, [
            'gate_entry_id' => $this->e->id, 'kind' => 'gross', 'weight_kg' => '25000', 'reason' => 'Indicator display broken',
        ], 'Manual gross'))->toThrow(ValidationException::class);
    });

    it('needs a reason, and for the Owner records a flagged manual weight at once', function () {
        $this->f->as($this->sa);
        $request = app(ApprovalService::class)->submit(ManualWeighmentOverride::KEY, [
            'gate_entry_id' => $this->e->id, 'kind' => 'gross', 'weight_kg' => '25000', 'reason' => 'Indicator display broken, read from printer tape',
        ], 'Manual gross for MNA211234');

        $w = Weighment::query()->sole();
        expect($w->source)->toBe('manual_override')
            ->and($w->approval_request_id)->toBe($request->id)
            ->and($w->reason)->toContain('printer tape')
            ->and($w->flags)->toContain('manual_override')
            ->and($this->e->fresh()->gross_kg)->toBe('25000.000');
    });
});

it('renders the slip PDF from the issued snapshot with a verification code', function () {
    $e = fullInbound($this);
    $pdf = app(SlipRenderer::class)->pdf($e);
    $slip = $e->events()->where('event', 'slip_issued')->sole();

    expect(substr($pdf, 0, 4))->toBe('%PDF')
        ->and(SlipRenderer::verificationCode($e->id, $slip->data))->toMatch('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/')
        ->and(SlipRenderer::verificationCode($e->id, ['net_kg' => '1'] + $slip->data))->not->toBe(SlipRenderer::verificationCode($e->id, $slip->data))
        ->and(fn () => $this->gate->issueSlip($e))->toThrow(BusinessRuleViolation::class, 'already');
});
