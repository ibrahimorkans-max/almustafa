<?php

declare(strict_types=1);

use App\Domain\Gate\GateService;
use App\Domain\Sales\SalesBillService;
use App\Filament\Pages\AccountsReports;
use App\Filament\Resources\Accounts\VoucherResource\Pages\ListVouchers;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ViewGateEntry;
use App\Filament\Resources\Sales\DispatchOrderResource\Pages\CreateDispatchOrder;
use App\Filament\Resources\Sales\DispatchOrderResource\Pages\ViewDispatchOrder;
use App\Filament\Resources\Sales\SalesBillResource\Pages\ViewSalesBill;
use App\Filament\Resources\Sales\SalesSaudaResource\Pages\CreateSalesSauda;
use App\Filament\Resources\Sales\SalesSaudaResource\Pages\ListSalesSaudas;
use App\Models\Accounts\ReceiptAllocation;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('ADM6'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function signInP6(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

dataset('phase 6 screens', [
    'sales saudas' => ['/admin/sales/saudas', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR']],
    'dispatch orders' => ['/admin/dispatch-orders', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER']],
    'invoices' => ['/admin/sales/invoices', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER']],
]);

it('opens sales screens only for the right roles', function (string $path, array $allowed) {
    foreach (['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('phase 6 screens');

it('keeps sales and purchase saudas on their own screens', function () {
    signInP6($this, $this->f->acc->owner);
    Livewire::test(CreateSalesSauda::class)
        ->fillForm([
            'party_id' => $this->f->multanBuyer->id, 'product_id' => $this->f->inv->rice->id, 'quantity_maund' => '200',
            'rate_rupees' => '4100', 'tolerance_percent' => '5', 'payment_days' => 15,
            'delivery_from' => now()->toDateString(), 'delivery_to' => now()->addDays(10)->toDateString(),
        ])
        ->call('create')->assertHasNoFormErrors();

    $sauda = Sauda::query()->latest('created_at')->orderByDesc('id')->first();
    expect($sauda->direction)->toBe('sale')->and($sauda->number)->toContain('/S/')->and($sauda->rate_paise_per_maund)->toBe(410000);
    Livewire::test(ListSalesSaudas::class)->assertCanSeeTableRecords([$sauda])->assertCanNotSeeTableRecords([$this->f->acc->sauda]);
});

it('runs a dispatch from order to gate pass through the screens', function () {
    signInP6($this, $this->f->dispatcher);
    Livewire::test(CreateDispatchOrder::class)
        ->fillForm(['sauda_id' => $this->f->sauda->id, 'godown_id' => $this->f->inv->store->id, 'quantity_kg' => '10000'])
        ->call('create')->assertHasNoFormErrors();
    $order = DispatchOrder::query()->sole();

    Livewire::test(ViewDispatchOrder::class, ['record' => $order->id])
        ->callAction('arrived', ['registration_no' => 'mna211234'])->assertRedirect();
    $entry = GateEntry::query()->sole();
    expect($entry->dispatch_order_id)->toBe($order->id);

    app(GateService::class)->recordWeighment($entry, 'tare', '9000', $this->f->inv->gate->scale, GateFixtures::readings('9000'), str_repeat('a', 64));
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->assertActionHidden('labTest')
        ->callAction('loaded', ['bag_count' => null])->assertNotified('Marked loaded');
    app(GateService::class)->recordWeighment($entry, 'gross', '19050', $this->f->inv->gate->scale, GateFixtures::readings('19050'), str_repeat('b', 64));
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('slip')->assertNotified('Slip issued');
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('gatePass')->assertNotified('The invoice must be posted by accounts before the gate pass.');

    signInP6($this, $this->f->acc->accountant);
    $bill = SalesBill::query()->sole();
    Livewire::test(ViewSalesBill::class, ['record' => $bill->id])->callAction('post', ['note' => null])->assertNotified('Invoice posted');
    Livewire::test(ViewSalesBill::class, ['record' => $bill->id])->callAction('pdf')->assertFileDownloaded();

    signInP6($this, $this->f->dispatcher);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('gatePass')->assertNotified('Gate pass issued');
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('gatePassPdf')->assertFileDownloaded();
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('exit')->assertNotified('Exit recorded');
    expect($entry->fresh()->status)->toBe('exited');
});

it('records a receipt against an invoice and shows receivables', function () {
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($this->f->billFor($entry));

    signInP6($this, $this->f->acc->accountant);
    Livewire::test(ListVouchers::class)
        ->callAction('receipt', [
            'party_id' => $this->f->karachiBuyer->id, 'into' => $this->f->acc->bank->id, 'amount' => '100000',
            'date' => now()->toDateString(), 'invoices' => [$bill->id],
        ])->assertHasNoActionErrors()->assertNotified();

    expect(ReceiptAllocation::query()->sole()->amount_paise)->toBe(10_000_000);
    Livewire::test(AccountsReports::class)->set('data.report', 'receivables')->assertSee($bill->number)->assertSee('3,22,100.00');
});
