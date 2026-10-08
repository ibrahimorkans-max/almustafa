<?php

declare(strict_types=1);

use App\Domain\Sales\SalesBillService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\GstReturnsPage;
use App\Filament\Pages\OperationalReportsPage;
use App\Filament\Resources\Sales\SalesBillResource\Pages\ViewSalesBill;
use App\Filament\Widgets\MoneyOverview;
use App\Filament\Widgets\OperationsOverview;
use App\Models\AuditLog;
use App\Models\Entity;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('ADM8'));
    app(TenantContext::class)->runAsSystem(fn () => Entity::query()->whereKey($this->f->entity->id)->first()
        ->forceFill(['gstin' => SalesFixtures::gstin('06', 'AAACG1234', 'C'), 'city' => 'Karnal', 'pincode' => '132001'])->save());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function signInP8(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

dataset('phase 8 screens', [
    'gst returns' => ['/admin/gst-returns', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'reports' => ['/admin/reports', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER']],
    'dashboard' => ['/admin', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
]);

it('opens GST, report and dashboard screens only for the right roles', function (string $path, array $allowed) {
    foreach (['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('phase 8 screens');

it('shows the month\'s GSTR-1 and downloads the portal JSON (audited)', function () {
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($this->f->billFor($entry));

    signInP8($this, $this->f->acc->accountant);
    Livewire::test(GstReturnsPage::class, ['period' => now('Asia/Kolkata')->format('Y-m')])
        ->set('period', now('Asia/Kolkata')->format('Y-m'))
        ->assertSee($bill->number)->assertSee('not locked yet')->assertSee('10063020')
        ->callAction('json')->assertFileDownloaded('gstr1-'.now('Asia/Kolkata')->format('Y-m').'.json');
    expect(AuditLog::query()->where('action', 'report.gst.exported')->exists())->toBeTrue();
});

it('generates the e-invoice from the invoice screen and prints it', function () {
    config(['ricemill.gst.einvoice_enabled' => true]);
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($this->f->billFor($entry));

    signInP8($this, $this->f->acc->accountant);
    Livewire::test(ViewSalesBill::class, ['record' => $bill->id])->callAction('irn')->assertNotified('IRN generated');
    Livewire::test(ViewSalesBill::class, ['record' => $bill->id])->assertSee('IRN')->assertActionHidden('irn')
        ->callAction('ewb', ['distance' => 180])->assertNotified('E-way bill generated');
    Livewire::test(ViewSalesBill::class, ['record' => $bill->id])->callAction('pdf')->assertFileDownloaded();
    expect($bill->fresh()->eway_bill_no)->not->toBeNull();
});

it('lists a register and exports it as CSV', function () {
    $this->f->loadAndWeigh($this->f->order());
    signInP8($this, $this->f->dispatcher);
    Livewire::test(OperationalReportsPage::class)->set('report', 'gate_register')
        ->assertSee('HR05AB1234')->assertSee('outbound')
        ->callAction('csv')->assertFileDownloaded();
});

it('shows money figures on the dashboard only to accounts roles', function () {
    signInP8($this, $this->f->acc->owner);
    Livewire::test(OperationsOverview::class)->assertSee('Vehicles inside')->assertSee('Waiting for my approval');
    expect(MoneyOverview::canView())->toBeTrue();

    signInP8($this, $this->f->dispatcher);
    expect(MoneyOverview::canView())->toBeFalse()->and(OperationsOverview::canView())->toBeTrue();
});
