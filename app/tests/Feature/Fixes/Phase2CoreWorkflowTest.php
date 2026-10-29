<?php

declare(strict_types=1);

use App\Domain\Access\EntityProvisioner;
use App\Domain\Gate\DeductionCalculator;
use App\Domain\Gate\GateService;
use App\Domain\Gate\QuickWeigh;
use App\Domain\Inventory\PaddyStockEntries;
use App\Domain\Inventory\StockLedger;
use App\Domain\Masters\CodeDirectory;
use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\CodeDirectoryPage;
use App\Filament\Resources\Gate\GateEntryResource\Pages\CreateGateEntry;
use App\Filament\Resources\Inventory\PaddyStockEntryResource\Pages\CreatePaddyStockEntry;
use App\Filament\Resources\Masters\PaddyVarietyResource\Pages\ManagePaddyVarieties;
use App\Models\Entity;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\PaddyStockEntry;
use App\Models\LoginAccount;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\InventoryFixtures;
use Tests\Support\Make;

/*
 * Phase 2 of the October 2026 list: paddy varieties, the continuous numeric gate token with With /
 * Without Jack, typed party names, deduction rules by weight, paddy stock entries, several production
 * outputs, automatic codes and the Code Directory.
 */
beforeEach(function () {
    $this->f = new InventoryFixtures(Make::entity('PH2'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->owner = $this->f->gate->owner;
    $this->f->as($this->owner);
});

function ph2SignIn(object $t, LoginAccount $account): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

it('keeps paddy varieties unique by name, with a permanent automatic code', function () {
    $v = PaddyVariety::query()->create(['name' => '1121 Kainat', 'category' => 'basmati']);
    expect($v->code)->toBe('PV-1121-0001');

    expect(fn () => PaddyVariety::query()->create(['name' => '  1121   kainat ']))->toThrow(BusinessRuleViolation::class, 'already a variety');

    $v->update(['name' => '1121 Kainat (new crop)', 'code' => 'HACKED']);
    expect($v->fresh()->code)->toBe('PV-1121-0001')->and($v->fresh()->name)->toBe('1121 Kainat (new crop)');

    ph2SignIn($this, $this->owner);
    Livewire::test(ManagePaddyVarieties::class)
        ->callAction('create', ['name' => 'Super Basmati', 'category' => 'basmati', 'unit' => 'maund', 'status' => 'active'])
        ->assertHasNoActionErrors();
    expect(PaddyVariety::query()->where('name', 'Super Basmati')->value('code'))->toBe('PV-SUPER-0002');
});

it('gives parties, products and vehicles a code when none is typed, and keeps a typed one', function () {
    $ali = Party::query()->create(['name' => 'Ali Traders', 'is_vendor' => true]);
    $rice = Product::query()->create(['name' => 'Kernel 25 kg', 'category' => 'rice']);
    $typed = Party::query()->create(['code' => 'OWN-7', 'name' => 'Own code']);
    $vehicle = Vehicle::query()->create(['registration_no' => 'LEA4567', 'vehicle_type' => 'truck']);

    expect($ali->code)->toMatch('/^PTY-ALI-\d{4}$/')
        ->and($rice->code)->toMatch('/^PRD-RICE-\d{4}$/')
        ->and($typed->code)->toBe('OWN-7')
        ->and($vehicle->code)->toMatch('/^VEH-\d{6}$/');
});

it('numbers gate tokens 000001, 000002 … in one series that does not restart the next day', function () {
    $gate = app(GateService::class);
    $first = $gate->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack', 'party_name' => 'Ali Rice Mills']);
    $second = $gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'without_jack'], occurredAt: now()->addDay());

    expect($first->tokenLabel())->toBe('000001')
        ->and($second->tokenLabel())->toBe('000002')
        ->and($first->jackLabel())->toBe('With Jack')
        ->and($second->token_no)->toBe(1) // the next day's queue starts again; the token does not
        ->and($first->tokenLabel())->not->toContain('J');

    expect(fn () => $gate->arrive(['registration_no' => 'LEB1234', 'jack_type' => 'JACK']))->toThrow(BusinessRuleViolation::class, 'With Jack');
});

it('keeps a typed party name without making a party record, and links a suggested registered party', function () {
    $parties = Party::query()->count();
    $gate = app(GateService::class);

    $typed = $gate->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack', 'party_name' => 'Rana Brothers Kabirwala']);
    expect($typed->party_id)->toBeNull()->and($typed->party_name)->toBe('Rana Brothers Kabirwala')->and(Party::query()->count())->toBe($parties);

    $seller = $this->f->gate->seller;
    $picked = $gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'with_jack', 'party_name' => $seller->name.' ('.$seller->code.')']);
    expect($picked->party_id)->toBe($seller->id)->and($picked->party_name)->toBeNull()->and($picked->partyLabel())->toBe($seller->name);

    // The weighbridge's quick form does not make parties either.
    [$entry] = app(QuickWeigh::class)->visit('Unknown Arhti Multan', 'LEB-1234', 'other_in', 'Husk');
    expect($entry->party_id)->toBeNull()->and($entry->party_name)->toBe('Unknown Arhti Multan')->and(Party::query()->count())->toBe($parties);
});

it('registers a vehicle at the gate screen with its type, and prints the token with the type beside the number', function () {
    ph2SignIn($this, $this->owner);
    Livewire::test(CreateGateEntry::class)
        ->fillForm(['registration_no' => 'MNA-21-1234', 'direction' => 'inbound', 'jack_type' => 'without_jack', 'party_name' => 'Ali Traders'])
        ->call('create')->assertHasNoFormErrors();
    $entry = GateEntry::query()->sole();
    expect($entry->jack_type)->toBe('without_jack')->and($entry->token_serial)->toBe(1);

    Livewire::test(CreateGateEntry::class)->fillForm(['registration_no' => 'LEA-4567', 'direction' => 'inbound'])
        ->call('create')->assertHasFormErrors(['jack_type' => 'required']);

    $this->get(route('filament.admin.gate.token', ['id' => $entry->id, 'paper' => 'a4', 'download' => 1]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="token-000001.pdf"');
});

it('deducts a fixed weight or a percentage of net, never more than the net, and only from the effective date', function () {
    $calc = app(DeductionCalculator::class);
    $fixed = new DeductionRule(['method' => 'fixed_weight', 'parameter' => 'fixed', 'factor' => 2000, 'status' => 'active']);
    $pct = new DeductionRule(['method' => 'percent_of_net', 'parameter' => 'net', 'factor' => 250, 'status' => 'active']);

    expect($calc->calculate('10000', null, [], [$fixed, $pct])['deduction_kg'])->toBe('252.000')
        ->and($calc->calculate('1', null, [], [$fixed])['deduction_kg'])->toBe('1.000'); // capped at the net

    $variety = PaddyVariety::query()->create(['name' => 'IRRI-6']);
    DeductionRule::query()->create(['name' => 'Later', 'product_id' => $this->f->paddy->id, 'method' => 'fixed_weight', 'parameter' => 'fixed', 'factor' => 5000,
        'effective_from' => now()->addDays(5)->toDateString()]);
    DeductionRule::query()->create(['name' => 'IRRI only', 'product_id' => $this->f->paddy->id, 'paddy_variety_id' => $variety->id, 'method' => 'fixed_weight',
        'parameter' => 'fixed', 'factor' => 1000]);
    $names = fn (?string $v) => DeductionRule::query()->applicable($this->f->paddy->id, $v, now())->pluck('name')->filter()->values()->all();

    expect($names(null))->toBe([])->and($names($variety->id))->toBe(['IRRI only'])
        ->and(DeductionRule::query()->where('name', 'Later')->value('code'))->toMatch('/^DR-\d{6}$/');
});

it('books a paddy stock entry into stock once, applying the weight rules, and never a token twice', function () {
    $this->f->as($this->f->storeManager);
    DeductionRule::query()->where('product_id', $this->f->paddy->id)->delete();
    DeductionRule::query()->create(['name' => 'Dust', 'product_id' => $this->f->paddy->id, 'method' => 'percent_of_net', 'parameter' => 'net', 'factor' => 100]);
    $svc = app(PaddyStockEntries::class);
    $before = app(StockLedger::class)->balance($this->f->paddy, $this->f->silo)->kg;

    $entry = $svc->record(['product_id' => $this->f->paddy->id, 'godown_id' => $this->f->silo->id, 'party_name' => 'Own crop',
        'gross_kg' => '12000', 'tare_kg' => '2000', 'rate_paise_per_maund' => 400000, 'bag_count' => 0]);
    expect($entry->net_kg)->toBe('10000.000')->and($entry->deduction_kg)->toBe('100.000')->and($entry->accepted_kg)->toBe('9900.000')
        ->and($entry->amount_paise)->toBe(99_000_000) // 247.5 maund × Rs 4,000
        ->and($entry->status)->toBe(PaddyStockEntry::DRAFT)
        ->and(app(StockLedger::class)->balance($this->f->paddy, $this->f->silo)->kg)->toBe($before);

    $svc->post($entry);
    expect(bcsub(app(StockLedger::class)->balance($this->f->paddy, $this->f->silo)->kg, $before, 3))->toBe('9900.000');
    expect(fn () => $svc->post($entry))->toThrow(BusinessRuleViolation::class, 'already posted');

    expect(fn () => $svc->calculate(['product_id' => $this->f->paddy->id, 'gross_kg' => '100', 'tare_kg' => '0', 'deduction_override_kg' => '5']))
        ->toThrow(BusinessRuleViolation::class, 'needs a reason');
    expect(fn () => $svc->calculate(['product_id' => $this->f->paddy->id, 'gross_kg' => '100', 'tare_kg' => '0', 'deduction_override_kg' => '200', 'deduction_override_reason' => 'Wet lot']))
        ->toThrow(BusinessRuleViolation::class, 'more than the net');

    // A token: one stock entry, and none for a token whose slip already booked it.
    $this->f->as($this->owner);
    $token = app(GateService::class)->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack']);
    $this->f->as($this->f->storeManager);
    $svc->record(['gate_entry_id' => $token->id, 'product_id' => $this->f->paddy->id, 'godown_id' => $this->f->silo->id, 'gross_kg' => '9000', 'tare_kg' => '3000']);
    expect(fn () => $svc->record(['gate_entry_id' => $token->id, 'product_id' => $this->f->paddy->id, 'godown_id' => $this->f->silo->id, 'gross_kg' => '9000', 'tare_kg' => '3000']))
        ->toThrow(BusinessRuleViolation::class, 'already has stock entry');
});

it('enters a paddy stock entry on screen and posts it', function () {
    ph2SignIn($this, $this->f->storeManager);
    Livewire::test(CreatePaddyStockEntry::class)
        ->fillForm(['entry_date' => now('Asia/Karachi')->toDateString(), 'product_id' => $this->f->paddy->id, 'godown_id' => $this->f->silo->id,
            'party_name' => 'Walk-in farmer', 'gross_kg' => '5000', 'tare_kg' => '1000', 'post' => true])
        ->assertSee('Accepted')
        ->call('create')->assertHasNoFormErrors();
    $entry = PaddyStockEntry::query()->sole();
    expect($entry->status)->toBe(PaddyStockEntry::POSTED)->and($entry->number)->toContain('/STK/');
});

it('records several outputs of a batch at once and reconciles input with outputs and loss', function () {
    $this->f->as($this->f->supervisor);
    $svc = app(ProductionService::class);
    $this->f->stock($this->f->paddy, $this->f->silo, '10000');
    $batch = $svc->open($this->f->paddy, openedAt: now()->subHours(3));
    $svc->recordInput($batch, $this->f->silo, '10000', now()->subHours(3), now()->subHours(2));
    $svc->recordOutputs($batch, [
        ['product' => $this->f->rice, 'godown' => $this->f->store, 'quantity_kg' => '5500'],
        ['product' => $this->f->broken, 'godown' => $this->f->store, 'quantity_kg' => '1000'],
        ['product' => $this->f->bran, 'godown' => $this->f->store, 'quantity_kg' => '800'],
        ['product' => $this->f->husk, 'godown' => $this->f->store, 'quantity_kg' => '2200'],
    ], now()->subHours(2), now()->subHour());

    $r = $svc->reconciliation($batch->fresh());
    expect($r['input_kg'])->toBe('10000.000')->and($r['output_kg'])->toBe('9500.000')->and($r['loss_kg'])->toBe('500.000')->and($r['loss_percent'])->toBe('5.00 %');
    expect(collect($r['rows'])->firstWhere('product', $this->f->rice->name)['percent'])->toBe('55.00 %');

    // One bad row books nothing.
    expect(fn () => $svc->recordOutputs($batch, [
        ['product' => $this->f->rice, 'godown' => $this->f->store, 'quantity_kg' => '10'],
        ['product' => $this->f->paddy, 'godown' => $this->f->store, 'quantity_kg' => '10'],
    ], now()->subHour(), now()))->toThrow(BusinessRuleViolation::class);
    expect($svc->reconciliation($batch->fresh())['output_kg'])->toBe('9500.000');
});

it('lists the mill\'s generated codes in the Code Directory, and no other mill\'s', function () {
    $ali = Party::query()->create(['name' => 'Ali Traders', 'is_vendor' => true]);
    $token = app(GateService::class)->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack', 'party_name' => 'Ali Traders']);

    // A mill of another company (an Owner sees all mills of their own company).
    $otherMill = Make::system(fn () => app(EntityProvisioner::class)->create([
        'code' => 'PH2B', 'name' => 'Mill PH2B', 'status' => Entity::STATUS_ACTIVE, 'customer_id' => Make::customer('OTHER')->id,
    ]));
    $other = new InventoryFixtures($otherMill);
    $other->as($other->gate->owner);
    $theirs = Party::query()->create(['name' => 'Ali Other Mill', 'is_vendor' => true]);
    $this->f->as($this->owner);

    $codes = CodeDirectory::query()->pluck('module', 'code')->all();
    expect($codes)->toHaveKey($ali->code)->and($codes[$ali->code])->toBe('party')
        ->and($codes)->toHaveKey('000001')->and($codes['000001'])->toBe('gate_token')
        ->and($codes)->toHaveKey($this->f->paddy->code)
        // Each mill numbers its own codes, so the other mill may have the same code: compare records.
        ->and(CodeDirectory::query()->pluck('record_id')->all())->not->toContain($theirs->id);

    ph2SignIn($this, $this->owner);
    Livewire::test(CodeDirectoryPage::class)->searchTable($ali->code)->assertSee('Ali Traders')
        ->callAction('excel')->assertFileDownloaded('code-directory.xlsx');
    expect($token->tokenLabel())->toBe('000001');
});
