<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\PartyStatementReport;
use App\Domain\Accounts\PartyStatementSharing;
use App\Domain\Documents\PaperSize;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Documents\PrintDelivery;
use App\Domain\Gate\GateService;
use App\Domain\Hr\ContractorBilling;
use App\Domain\Hr\ContractorVehicles;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\PartyStatementPage;
use App\Filament\Pages\SearchResultsPage;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Gate\GateEntryResource\Pages\CreateGateEntry;
use App\Filament\Resources\Hr\ContractorBillResource\Pages\CreateContractorBill;
use App\Filament\Resources\Masters\VehicleResource;
use App\Mail\PartyStatementMail;
use App\Models\Accounts\StatementShare;
use App\Models\Gate\GateEntry;
use App\Models\Hr\ContractorRate;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

/*
 * Labour contractors paid by weight per vehicle (rates, versions, vehicle list, contractor tokens, bills,
 * partial payments), the party / contractor statement and its sharing, the top-bar search, direct
 * printing and paper per kind of document.
 */

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('CWS'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f->as($this->f->owner);
    $this->gate = app(GateService::class);
    $this->cv = app(ContractorVehicles::class);
    $this->bills = app(ContractorBilling::class);
    $this->contractor = Party::query()->create(['name' => 'Ali Labour Co', 'is_vendor' => true]);
});

function cwSignIn(object $t, LoginAccount $account): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

/** A contractor token: arrived with the contractor's vehicle, weights final. */
function cwToken(object $t, Vehicle $v, string $netKg, int $daysAgo = 0): GateEntry
{
    $e = $t->gate->arrive(['registration_no' => $v->registration_no, 'jack_type' => 'with_jack', 'contractor_party_id' => $t->contractor->id, 'contractor_work' => 'unloading'],
        occurredAt: now()->subDays($daysAgo));
    $e->forceFill(['gross_kg' => bcadd($netKg, '10000', 3), 'tare_kg' => '10000', 'net_kg' => $netKg])->save();

    return $e->fresh();
}

it('pays contractors by weight only: per maund, per kg or per custom weight, with permanent codes', function () {
    $v = $this->cv->addVehicle($this->contractor, 'LEA-4567', 'truck');
    expect($this->contractor->fresh())->is_contractor->toBeTrue()->contractor_code->toBe('CTR-ALI-0001')
        ->and($v->contractor_party_id)->toBe($this->contractor->id);

    $maund = ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'maund', 'rate_paise' => 12_00, 'effective_from' => '2026-01-01']);
    expect($maund->code)->toBe('CRL-000001')->and($maund->chargeFor('20000'))->toBe(500 * 12_00);           // 500 maund × Rs 12
    $custom = ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'work' => 'loading', 'basis' => 'custom',
        'basis_qty' => '100', 'basis_unit' => 'kg', 'rate_paise' => 50_00, 'effective_from' => '2026-01-01']);
    expect($custom->basisLabel())->toBe('Per 100 kg')->and($custom->chargeFor('12345'))->toBe(6172_50);   // 123.45 × Rs 50
    $kg = ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'work' => 'transport', 'basis' => 'kg', 'rate_paise' => 1_50, 'effective_from' => '2026-01-01']);
    expect($kg->chargeFor('1000'))->toBe(1500_00);

    // No fixed amount per vehicle or trip any more, and a custom rate needs its weight.
    expect(fn () => ContractorRate::query()->create(['party_id' => $this->contractor->id, 'basis' => 'vehicle', 'rate_paise' => 500_00, 'effective_from' => '2026-01-01']))
        ->toThrow(BusinessRuleViolation::class, 'by weight');
    expect(fn () => ContractorRate::query()->create(['party_id' => $this->contractor->id, 'basis' => 'custom', 'rate_paise' => 50_00, 'effective_from' => '2026-01-01', 'work' => 'unloading']))
        ->toThrow(BusinessRuleViolation::class, 'custom weight');
});

it('keeps rate versions without overlaps, and a rate on a bill cannot change', function () {
    $v = $this->cv->addVehicle($this->contractor, 'LEA4567', 'truck');
    $old = ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'maund', 'rate_paise' => 10_00, 'effective_from' => now()->subMonth()->toDateString()]);
    // A new version closes the open one the day before.
    $new = ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'maund', 'rate_paise' => 12_00, 'effective_from' => now()->subDays(5)->toDateString()]);
    expect($old->fresh()->effective_to->toDateString())->toBe(now()->subDays(6)->toDateString());
    // A rate inside a closed version's days conflicts.
    expect(fn () => ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'kg', 'rate_paise' => 30,
        'effective_from' => now()->subDays(20)->toDateString(), 'effective_to' => now()->subDays(10)->toDateString()]))->toThrow(BusinessRuleViolation::class, 'already covers');

    // The rate of the arrival day is used: old for a token 10 days ago, new for today.
    $before = cwToken($this, $v, '4000', 10);
    $today = cwToken($this, $v, '4000');
    expect($this->cv->rateFor($this->contractor, $before)->id)->toBe($old->id)->and($this->cv->rateFor($this->contractor, $today)->id)->toBe($new->id);

    $this->bills->prepare($this->contractor, CarbonImmutable::now('Asia/Karachi')->subDays(12), CarbonImmutable::now('Asia/Karachi'), 'vehicles', 0);
    $new->refresh();
    expect(fn () => $new->update(['rate_paise' => 20_00]))->toThrow(BusinessRuleViolation::class, 'new version');
});

it('keeps each vehicle with one contractor', function () {
    $v = $this->cv->addVehicle($this->contractor, 'LEA4567', 'truck');
    $other = Party::query()->create(['name' => 'Bashir Labour', 'is_vendor' => true]);

    expect(fn () => $this->cv->linkVehicle($v, $other))->toThrow(BusinessRuleViolation::class, 'cannot be linked to another contractor');
    expect(fn () => $this->gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'with_jack', 'contractor_party_id' => $other->id]))
        ->toThrow(BusinessRuleViolation::class, 'works for another contractor');

    $this->f->as(Make::account($this->f->entity, 'SUPERVISOR'));
    expect(fn () => $this->cv->linkVehicle($v, null))->toThrow(BusinessRuleViolation::class, 'Only the Owner');
    $this->f->as($this->f->owner);
    expect($this->cv->linkVehicle($v, null)->contractor_party_id)->toBeNull();
});

it('issues contractor tokens in the same token series, charges by final weight and bills them today', function () {
    $v = $this->cv->addVehicle($this->contractor, 'LEA4567', 'truck');
    ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'maund', 'rate_paise' => 12_00, 'effective_from' => now()->subMonth()->toDateString()]);
    $plain = $this->gate->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack']);
    $token = cwToken($this, $v, '20000');
    $waiting = $this->gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'without_jack', 'contractor_party_id' => $this->contractor->id]);

    expect($token->token_serial)->toBe($plain->token_serial + 1)->and($token->contractor_party_id)->toBe($this->contractor->id)
        ->and($token->vehicle_id)->toBe($v->id)->and($waiting->contractor_work)->toBe('unloading');

    $today = CarbonImmutable::now('Asia/Karachi');
    $bill = $this->bills->prepare($this->contractor, $today, $today, 'vehicles', 0);
    $line = $bill->lines()->sole();
    expect($bill->number)->toBe('CBL-000001')->and($bill->gross_paise)->toBe(500 * 12_00)
        ->and($line->source)->toMatchArray(['token' => $token->tokenLabel(), 'gross_kg' => '30000.000', 'tare_kg' => '10000.000', 'net_kg' => '20000.000',
            'billable_kg' => '20000.000', 'basis' => 'maund', 'basis_kg' => '40', 'rate_paise' => 12_00])
        // The token still waiting for its weight is not billed yet.
        ->and($waiting->fresh()->contractor_bill_id)->toBeNull();
    expect(fn () => $this->bills->prepare($this->contractor, $today, $today, 'vehicles', 0))->toThrow(BusinessRuleViolation::class, 'still waiting for their final weight');
});

it('takes deductions and partial payments, keeping the balance and the contractor ledger', function () {
    $v = $this->cv->addVehicle($this->contractor, 'LEA4567', 'truck');
    ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'maund', 'rate_paise' => 10_00, 'effective_from' => now()->subMonth()->toDateString()]);
    cwToken($this, $v, '40000', 1);
    $from = CarbonImmutable::now('Asia/Karachi')->subDays(3);
    $to = CarbonImmutable::now('Asia/Karachi')->subDay();

    expect(fn () => $this->bills->prepare($this->contractor, $from, $to, 'vehicles', 0, deductionPaise: 500_00))->toThrow(BusinessRuleViolation::class, 'what the deduction is for');
    $bill = $this->bills->prepare($this->contractor, $from, $to, 'vehicles', 0, deductionPaise: 500_00, deductionNote: 'Two torn bags');
    expect($bill->gross_paise)->toBe(10000_00)->and($bill->payable_paise)->toBe(9500_00);

    $cash = app(ChartOfAccounts::class)->system('cash');
    expect(fn () => $this->bills->pay($bill, 100_00, $cash, CarbonImmutable::now('Asia/Karachi')))->toThrow(BusinessRuleViolation::class, 'approved');
    $this->f->as($this->f->owner2);
    $this->bills->approve($bill);
    $this->bills->pay($bill, 4000_00, $cash, CarbonImmutable::now('Asia/Karachi'), 'Part 1');
    $bill->refresh();
    expect($bill->paid_paise)->toBe(4000_00)->and($bill->balancePaise())->toBe(5500_00)->and($bill->paymentStatus())->toBe('partial');
    expect(fn () => $this->bills->pay($bill, 6000_00, $cash, CarbonImmutable::now('Asia/Karachi')))->toThrow(BusinessRuleViolation::class, 'still due');
    $this->bills->pay($bill, 5500_00, $cash, CarbonImmutable::now('Asia/Karachi'));
    expect($bill->fresh()->paymentStatus())->toBe('paid');

    // The contractor's ledger is settled: 9,500 credited by the bill, 9,500 debited by the payments.
    $s = app(PartyStatementReport::class)->build($this->contractor->fresh(), $from, CarbonImmutable::now('Asia/Karachi'), ['view' => 'contractor']);
    expect($s['closing'])->toBe(0)->and($s['contractor']['bills'][0])->toMatchArray(['payable' => 9500_00, 'paid' => 9500_00, 'balance' => 0, 'pay_status' => 'paid'])
        ->and($s['contractor']['payments'])->toHaveCount(2)->and($s['contractor']['tokens'][0]['charge'])->toBe(10000_00);
});

it('shows a party statement from the books and shares it without claiming delivery', function () {
    Mail::fake();
    $v = $this->cv->addVehicle($this->contractor, 'LEA4567', 'truck');
    ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'maund', 'rate_paise' => 10_00, 'effective_from' => now()->subMonth()->toDateString()]);
    cwToken($this, $v, '4000');
    $party = $this->contractor->fresh();
    $from = CarbonImmutable::now('Asia/Karachi')->startOfMonth();
    $to = CarbonImmutable::now('Asia/Karachi');
    $sharing = app(PartyStatementSharing::class);

    $pdf = $sharing->pdf($party, $from, $to, ['view' => 'contractor']);
    expect($pdf)->toStartWith('%PDF')->and(PdfRenderer::$lastHtml)->toContain('CONTRACTOR STATEMENT')->toContain('CTR-ALI-0001')->toContain('Not billed');

    // WhatsApp without the mill's connection: a wa.me link for a person to send, recorded as prepared only.
    $wa = $sharing->whatsapp($party, '0300 1234567', 'Your statement', $from, $to);
    expect($wa['status'])->toBe('prepared')->and($wa['url'])->toStartWith('https://wa.me/923001234567?text=');
    // E-mail: the PDF attached, the subject as written; recorded as handed to the mail server.
    $mail = $sharing->email($party, 'ali@example.com', 'Statement for October', 'Please see attached.', $from, $to);
    expect($mail['status'])->toBe('accepted');
    Mail::assertSent(PartyStatementMail::class, fn (PartyStatementMail $m) => $m->subjectLine === 'Statement for October' && count($m->attachments()) === 1);
    expect(StatementShare::query()->orderBy('created_at')->pluck('status', 'channel')->all())->toBe(['whatsapp' => 'prepared', 'email' => 'accepted']);

    // The secure link opens the PDF; an altered one is refused.
    $link = $sharing->link($party, $from, $to)['url'];
    app('auth')->forgetGuards();
    $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(str_replace('party=', 'party=0', $link))->assertForbidden();

    cwSignIn($this, $this->f->owner);
    Livewire::test(PartyStatementPage::class)->set('data.party_id', $party->id)->set('data.view', 'contractor')
        ->assertSee('CTR-ALI-0001')->assertSee('Sharing history')
        ->callAction('print')->assertDispatched('rm-print');
});

it('finds tokens, vehicles and parties from the top-bar search, within the mill and the person\'s rights', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack', 'party_name' => 'Rasheed Ahmad']);
    cwSignIn($this, $this->f->owner);

    expect(GateEntryResource::getGlobalSearchResults((string) $e->token_serial)->pluck('url'))->toContain(GateEntryResource::getUrl('view', ['record' => $e]))
        ->and(GateEntryResource::getGlobalSearchResults('mna-21')->count())->toBe(1)
        ->and(GateEntryResource::getGlobalSearchResults('rasheed')->count())->toBe(1)
        ->and(VehicleResource::getGlobalSearchResults('MNA-21-1234')->count())->toBe(1);

    Livewire::test(SearchResultsPage::class)->set('q', 'Aslam')->assertSee('Muhammad Aslam');

    // Another mill's records never show.
    $f2 = new GateFixtures(Make::entity('CWT'));
    $f2->as($f2->owner);
    app(GateService::class)->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack']);
    cwSignIn($this, $this->f->owner);
    expect(GateEntryResource::getGlobalSearchResults('MNA211234')->count())->toBe(1);

    // Someone without the gate right does not search gate entries.
    cwSignIn($this, Make::account($this->f->entity, 'ACCOUNTANT'));
    expect(GateEntryResource::canGloballySearch())->toBe(GateEntryResource::canViewAny());
});

it('prints straight to the print dialog for the person who asked only, on the paper chosen for that kind of document', function () {
    cwSignIn($this, $this->f->owner);
    $url = PrintDelivery::stash('%PDF-1.4 test', 'slip.pdf');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    cwSignIn($this, $this->f->owner2);
    $this->get($url)->assertStatus(410);
    // Ordinary pages still cannot be framed.
    $this->get(GateEntryResource::getUrl())->assertHeader('X-Frame-Options', 'DENY');

    // Tokens on the 58 mm roll for this mill, whatever the computer has chosen; other documents as chosen.
    app(SettingsService::class)->put('print.paper.token', 'roll58', $this->f->entity->id);
    expect(PaperSize::resolve('gate.token-slip', $this->f->entity->id))->toBe('roll58')
        ->and(PaperSize::resolve('reports.screen', $this->f->entity->id))->toBeNull()
        ->and(PaperSize::kindOf('sales.invoice'))->toBe('invoice');
});

it('issues a contractor token from the gate screen, the contractor\'s vehicle filling the number', function () {
    $v = $this->cv->addVehicle($this->contractor, 'LEA4567', 'truck');
    ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'maund', 'rate_paise' => 12_00, 'effective_from' => now()->subMonth()->toDateString()]);
    cwSignIn($this, $this->f->owner);

    Livewire::test(CreateGateEntry::class)
        ->fillForm(['contractor_party_id' => $this->contractor->id])
        ->fillForm(['contractor_vehicle_id' => $v->id])
        ->assertFormSet(['registration_no' => 'LEA4567', 'vehicle_type' => 'truck'])
        ->assertSee('Rate CRL-000001: Rs 12.00 per maund (40 kg)')
        ->fillForm(['jack_type' => 'with_jack', 'contractor_work' => 'unloading'])
        ->call('create')->assertHasNoFormErrors();
    expect(GateEntry::query()->latest('arrived_at')->first())->contractor_party_id->toBe($this->contractor->id)->vehicle_id->toBe($v->id);
});

it('prepares the bill for tokens that have a rate, leaves out the ones that do not, and says why', function () {
    // A token taken before its vehicle was registered is matched to the vehicle by its number.
    $old = $this->gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'with_jack', 'contractor_party_id' => $this->contractor->id], occurredAt: now()->subDays(10));
    $old->forceFill(['net_kg' => '4000'])->save();
    $v = $this->cv->addVehicle($this->contractor, 'LEA4567', 'truck');
    expect($old->fresh()->vehicle_id)->toBeNull();
    $rate = ContractorRate::query()->create(['party_id' => $this->contractor->id, 'vehicle_id' => $v->id, 'basis' => 'maund', 'rate_paise' => 10_00, 'effective_from' => now()->subDays(3)->toDateString()]);
    $recent = cwToken($this, $v, '8000', 1);
    $from = CarbonImmutable::now('Asia/Karachi')->subDays(15);
    $to = CarbonImmutable::now('Asia/Karachi');

    $svc = app(ContractorBilling::class);
    $bill = $svc->prepare($this->contractor, $from, $to, 'vehicles', 0);
    expect($bill->lines()->count())->toBe(1)->and($bill->gross_paise)->toBe(200 * 10_00)
        ->and($svc->skipped)->toHaveCount(1)->and($svc->skipped[0])->toContain('LEA-4567')
        ->and($old->fresh()->contractor_bill_id)->toBeNull()->and($recent->fresh()->contractor_bill_id)->toBe($bill->id);

    // Nothing with a rate: refused, naming the tokens and what to do; the screen says so plainly.
    expect(fn () => $svc->prepare($this->contractor, $from, $to, 'vehicles', 0))->toThrow(BusinessRuleViolation::class, 'No rate covers the days');
    cwSignIn($this, $this->f->owner);
    Livewire::test(CreateContractorBill::class)
        ->fillForm(['party_id' => $this->contractor->id, 'basis' => 'vehicles', 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString()])
        ->call('create')->assertHasFormErrors(['party_id'])->assertNotified('The bill was not prepared');

    // Backdating the rate lets the old token be billed; it is then matched through its number.
    $rate->update(['effective_from' => now()->subDays(12)->toDateString()]);
    Livewire::test(CreateContractorBill::class)
        ->fillForm(['party_id' => $this->contractor->id, 'basis' => 'vehicles', 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString()])
        ->call('create')->assertHasNoFormErrors();
    expect($old->fresh()->contractor_bill_id)->not->toBeNull();
});
