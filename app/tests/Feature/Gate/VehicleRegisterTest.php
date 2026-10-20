<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Gate\GateService;
use App\Domain\Gate\VehicleRegister;
use App\Filament\Pages\VehicleRegisterPage;
use App\Models\Accounts\PurchaseBill;
use App\Models\Gate\GateEntry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('VRG'));
    $this->sauda = $this->f->confirmedSauda('100000', 100000); // Rs 1,000 per maund
    $this->sup = Make::account($this->f->entity, 'SUPERVISOR');
    $this->f->as($this->sup);
    $this->gate = app(GateService::class);
    $this->register = app(VehicleRegister::class);
    $this->today = CarbonImmutable::now('Asia/Karachi');
});

function registerWeigh(object $t, GateEntry $e, string $kind, string $kg): void
{
    $t->gate->recordWeighment($e, $kind, $kg, $t->f->scale, GateFixtures::readings($kg), str_repeat('a', 64));
}

/** Inbound paddy up to the empty-truck weight (the slip not issued yet). */
function registerInbound(object $t): GateEntry
{
    $e = $t->gate->arrive(['registration_no' => 'mna 21 1234', 'sauda_id' => $t->sauda->id, 'bag_count' => 400, 'driver_name' => 'Aslam']);
    $t->gate->recordLabTest($e, ['moisture_bp' => 1850, 'foreign_matter_bp' => 350, 'grade' => 'A']);
    $t->gate->decideLab($e, true);
    registerWeigh($t, $e, 'gross', '25480.000');
    $t->gate->markUnloaded($e);
    registerWeigh($t, $e, 'tare', '10230.000');

    return $e->fresh();
}

it('shows how the weight was worked out, step by step', function () {
    $e = registerInbound($this);
    $steps = implode("\n", $this->register->weightSteps($e));

    // net 15,250; bags 400 × 600 g = 240; moisture 18.5 % → 228.75; payable 14,781.25
    expect($steps)->toContain('Net = 25,480 kg − 10,230 kg = 15,250 kg')
        ->and($steps)->toContain('400 bags × 600 g = 240 kg')
        ->and($steps)->toContain('Moisture 18.5 %')
        ->and($steps)->toContain('Total deduction = 468.75 kg')
        ->and($steps)->toContain('Payable = net − deduction = 15,250 kg − 468.75 kg = 14,781.25 kg (369.53 maund)');
});

it('estimates the payable price before the bill, then shows the bill figures', function () {
    $e = registerInbound($this);
    $estimate = $this->register->price($e);

    // 14,781.25 kg ÷ 40 × Rs 1,000 = Rs 3,69,531.25; rate cut Rs 4 / maund = Rs 1,478.13
    expect($estimate['state'])->toBe('estimate')
        ->and($estimate['gross_paise'])->toBe(36953125)
        ->and($estimate['cut_paise'])->toBe(147813)
        ->and($estimate['net_paise'])->toBe(36805312 + $estimate['round_off_paise'])
        ->and(implode("\n", $estimate['steps']))->toContain('369.53 maund × Rs 1,000.00');

    $this->f->settle($this->gate, $e);
    $this->gate->issueSlip($e);
    $bill = PurchaseBill::query()->where('gate_entry_id', $e->id)->sole();
    $billed = $this->register->price($e->fresh());
    expect($billed['state'])->toBe('bill_draft')
        ->and($billed['bill'])->toBe($bill->number)
        ->and($billed['gross_paise'])->toBe($estimate['gross_paise'])
        ->and($billed['net_paise'])->toBe($bill->net_paise);
});

it('lists the day\'s vehicles in and out with totals, and not other days', function () {
    $in = registerInbound($this);
    $out = $this->gate->arrive(['registration_no' => 'LES 9 777', 'direction' => 'outbound', 'purpose' => 'dispatch']);
    $old = $this->gate->arrive(['registration_no' => 'OLD 1 111']);
    Make::system(fn () => GateEntry::query()->whereKey($old->id)->update(['arrived_at' => now()->subDays(3)]));

    $day = $this->register->day($this->today);
    expect(collect($day['sides']['inbound']['rows'])->pluck('number')->all())->toBe([$in->number])
        ->and(collect($day['sides']['outbound']['rows'])->pluck('number')->all())->toBe([$out->number])
        ->and($day['sides']['inbound']['totals'])->toMatchArray(['vehicles' => 1, 'inside' => 1, 'bags' => 400, 'net_kg' => '15250.000', 'payable_kg' => '14781.250'])
        ->and($day['sides']['outbound']['rows'][0]['price']['state'])->toBe('none');

    expect($this->register->day($this->today->subDays(3))['sides']['inbound']['rows'])->toHaveCount(1);
});

it('prints one vehicle\'s slip and the whole day\'s register, and the page works', function () {
    $e = registerInbound($this);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = Make::account($this->f->entity, 'SUPER_ADMIN');
    $this->actingAs($admin, 'web')->withSession(Make::webSession($admin));
    app(AuthContextBinder::class)->bind($admin);

    $this->get(route('filament.admin.vehicle-register.vehicle', ['id' => $e->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(PdfRenderer::$lastHtml)->toContain('VEHICLE SLIP')->toContain('How the payable price was worked out')->toContain('Vehicle and driver')->toContain('Arrived at the gate')->toContain('Checked by (second person)');

    $this->get(route('filament.admin.vehicle-register.day', ['date' => $this->today->toDateString()]))->assertOk();
    expect(PdfRenderer::$lastHtml)->toContain('VEHICLE REGISTER')->toContain($e->number)->toContain('Vehicles out (dispatch)');

    $this->get(route('filament.admin.vehicle-register.weighment', ['id' => $e->id]))->assertStatus(409); // no slip issued yet
    $this->get(route('filament.admin.vehicle-register.day', ['date' => 'not-a-date']))->assertNotFound();

    $this->get(route('filament.admin.vehicle-register.day', ['date' => $this->today->toDateString(), 'slips' => 1]))->assertOk();
    expect(PdfRenderer::$lastHtml)->toContain('VEHICLE SLIP')->toContain('Timeline')->toContain('Lab test');

    Livewire::test(VehicleRegisterPage::class)
        ->assertSee($e->number)->assertSee('How each vehicle')->assertSee('Print all vehicle slips')
        ->mountTableAction('details', $e)->assertSee('Vehicle and driver')->assertSee('Licence')->assertSee('Weighments (from the weighbridge)')
        ->assertSee('Moisture')->assertSee('Timeline')->assertSee('Rs 3,68,053.00')->assertSee('Print this vehicle');

    Livewire::test(VehicleRegisterPage::class)
        ->call('downloadExcel')->assertFileDownloaded('vehicle-register-'.$this->today->toDateString().'.xlsx')
        ->set('side', 'outbound')->assertDontSee($e->number);
});

it('is closed to people without gate rights', function () {
    $e = registerInbound($this);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $clerk = Make::account($this->f->entity, 'HR_MANAGER');
    $this->actingAs($clerk, 'web')->withSession(Make::webSession($clerk));
    app(AuthContextBinder::class)->bind($clerk);

    expect(VehicleRegisterPage::canAccess())->toBeFalse();
    $this->get(route('filament.admin.vehicle-register.vehicle', ['id' => $e->id]))->assertForbidden();
});
