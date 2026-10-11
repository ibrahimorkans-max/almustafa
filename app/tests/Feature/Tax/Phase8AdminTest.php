<?php

declare(strict_types=1);

use App\Domain\Sales\SalesBillService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\OperationalReportsPage;
use App\Filament\Pages\SalesTaxReturnsPage;
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
        ->forceFill(['city' => 'Lodhran', 'province' => 'Punjab', 'postal_code' => '59320'])->save());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function signInP8(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

dataset('phase 8 screens', [
    'tax registers' => ['/admin/tax-registers', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'reports' => ['/admin/reports', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER']],
    'dashboard' => ['/admin', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
]);

it('opens tax, report and dashboard screens only for the right roles', function (string $path, array $allowed) {
    foreach (['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('phase 8 screens');

it('shows the month\'s sales-tax register and downloads Annex-C and the withholding CSV (audited)', function () {
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($this->f->billFor($entry));
    $month = now('Asia/Karachi')->format('Y-m');

    signInP8($this, $this->f->acc->accountant);
    Livewire::test(SalesTaxReturnsPage::class, ['period' => $month])
        ->set('period', $month)
        ->assertSee($bill->number)->assertSee('not locked yet')->assertSee('1006.3000')->assertSee('Karachi Rice House')
        ->callAction('salesCsv')->assertFileDownloaded("annex-c-{$month}.csv")
        ->callAction('whtCsv')->assertFileDownloaded("wht-153-{$month}.csv");
    expect(AuditLog::query()->where('action', 'report.tax.exported')->count())->toBe(2);
});

it('prints the sales tax invoice from the invoice screen', function () {
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($this->f->billFor($entry));

    signInP8($this, $this->f->acc->accountant);
    Livewire::test(ViewSalesBill::class, ['record' => $bill->id])->assertSee('Sales tax invoice')->assertSee('1006.3000')
        ->callAction('pdf')->assertFileDownloaded(str_replace('/', '-', (string) $bill->number).'.pdf');
});

it('lists a register and exports it as CSV', function () {
    $this->f->loadAndWeigh($this->f->order());
    signInP8($this, $this->f->dispatcher);
    Livewire::test(OperationalReportsPage::class)->set('report', 'gate_register')
        ->assertSee('MNA211234')->assertSee('outbound')
        ->callAction('csv')->assertFileDownloaded();
});

it('shows money figures on the dashboard only to accounts roles', function () {
    signInP8($this, $this->f->acc->owner);
    Livewire::test(OperationsOverview::class)->assertSee('Vehicles inside')->assertSee('Waiting for my approval');
    expect(MoneyOverview::canView())->toBeTrue();

    signInP8($this, $this->f->dispatcher);
    expect(MoneyOverview::canView())->toBeFalse()->and(OperationsOverview::canView())->toBeTrue();
});
