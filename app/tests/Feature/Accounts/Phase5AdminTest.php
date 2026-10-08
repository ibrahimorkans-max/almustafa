<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Approvals\ApprovalService;
use App\Filament\Pages\AccountsReports;
use App\Filament\Resources\Accounts\BankFileResource\Pages\ListBankFiles;
use App\Filament\Resources\Accounts\BankStatementLineResource\Pages\ListBankStatementLines;
use App\Filament\Resources\Accounts\PaymentResource\Pages\CreatePayment;
use App\Filament\Resources\Accounts\PeriodLockResource\Pages\ListPeriodLocks;
use App\Filament\Resources\Accounts\PurchaseBillResource\Pages\ViewPurchaseBill;
use App\Filament\Resources\Accounts\VoucherResource\Pages\ListVouchers;
use App\Filament\Resources\Masters\ProductResource\Pages\ManageProducts;
use App\Models\Accounts\BankFile;
use App\Models\Accounts\BankStatementLine;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PeriodLock;
use App\Models\Accounts\Voucher;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('ADM5'));
    $this->entry = $this->f->receiveLot();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function signInP5(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

dataset('phase 5 screens', [
    'purchase bills' => ['/admin/purchase-bills', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'payments' => ['/admin/payments', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'vouchers' => ['/admin/vouchers', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'ledgers' => ['/admin/ledgers', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'bank files' => ['/admin/bank-files', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'reconciliation' => ['/admin/bank-reconciliation', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'reports' => ['/admin/accounts-reports', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
    'period locks' => ['/admin/period-locks', ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT']],
]);

it('opens accounts screens only for the right roles', function (string $path, array $allowed) {
    foreach (['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('phase 5 screens');

it('runs bill → payment request → release → bank file from the screens', function () {
    $bill = $this->f->billFor($this->entry);
    signInP5($this, $this->f->accountant);
    Livewire::test(ViewPurchaseBill::class, ['record' => $bill->id])
        ->assertSee('3,68,053.00')
        ->callAction('post', ['note' => 'Checked'])->assertNotified('Bill posted');

    Livewire::test(CreatePayment::class)
        ->fillForm([
            'party_id' => $this->f->inv->gate->seller->id, 'mode' => 'neft', 'from_ledger_id' => $this->f->bank->id,
            'payment_date' => now()->toDateString(), 'amount' => '368053', 'bills' => [$bill->id],
        ])
        ->call('create')->assertHasNoFormErrors();
    $payment = Payment::query()->sole();
    expect($payment->amount_paise)->toBe(36_805_300)->and($payment->allocations()->sole()->amount_paise)->toBe(36_805_300);

    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($payment->approval_request_id), $this->f->owner);

    signInP5($this, $this->f->owner);
    Livewire::test(ListBankFiles::class)->callAction('create', ['bank' => $this->f->bank->id])->assertNotified();
    expect(BankFile::query()->sole()->payment_count)->toBe(1);
});

it('posts receipts and journals but keeps payments out of manual entry', function () {
    signInP5($this, $this->f->accountant);
    Livewire::test(ListVouchers::class)
        ->callAction('receipt', ['party_id' => $this->f->inv->gate->seller->id, 'into' => $this->f->bank->id, 'amount' => '5000', 'date' => now()->toDateString()])
        ->assertHasNoActionErrors()->assertNotified();
    expect(Voucher::query()->where('type', 'receipt')->sole()->total_paise)->toBe(5_000_00);

    $undoRepeaterFake = Repeater::fake();
    $capital = app(ChartOfAccounts::class)->system('capital');
    $brokerage = app(ChartOfAccounts::class)->system('brokerage');
    Livewire::test(ListVouchers::class)
        ->callAction('journal', ['date' => now()->toDateString(), 'narration' => 'Withdrawal hidden as journal', 'lines' => [
            ['account' => $this->f->bank->id, 'dr' => null, 'cr' => '10'],
            ['account' => $capital->id, 'dr' => '10', 'cr' => null],
        ]])
        // Bank / cash are not offered in the form; even if posted directly, the service refuses them.
        ->assertNotified('Bank and cash ledgers cannot be used in a journal; use a payment, receipt or contra.');
    expect(Voucher::query()->where('type', 'journal')->count())->toBe(0);

    Livewire::test(ListVouchers::class)
        ->callAction('journal', ['date' => now()->toDateString(), 'narration' => 'Brokerage borne by proprietor', 'lines' => [
            ['account' => $brokerage->id, 'dr' => '10', 'cr' => null],
            ['account' => $capital->id, 'dr' => null, 'cr' => '10'],
        ]])->assertHasNoActionErrors();
    expect(Voucher::query()->where('type', 'journal')->sole()->total_paise)->toBe(1000);
    $undoRepeaterFake();
});

it('imports a statement file and shows unmatched lines', function () {
    signInP5($this, $this->f->accountant);
    $csv = "Date,Description,Reference,Debit,Credit\n".now('Asia/Kolkata')->format('d/m/Y').",BANK CHARGES,,17.70,\n";
    Livewire::test(ListBankStatementLines::class)
        ->callAction('import', ['bank' => $this->f->bank->id, 'file' => UploadedFile::fake()->createWithContent('stmt.csv', $csv)])
        ->assertNotified('1 lines imported, 0 matched automatically');

    expect(BankStatementLine::query()->sole()->debit_paise)->toBe(1770);
});

it('renders every report and locks a month', function () {
    signInP5($this, $this->f->owner);
    foreach (array_keys(AccountsReports::REPORTS) as $report) {
        Livewire::test(AccountsReports::class)->set('data.report', $report)->assertOk();
    }
    Livewire::test(AccountsReports::class)->assertSee('Trial balance');

    Livewire::test(ListPeriodLocks::class)->callAction('lock', ['period' => now('Asia/Kolkata')->subMonthNoOverflow()->format('Y-m')])->assertNotified();
    expect(PeriodLock::query()->sole()->locked)->toBeTrue();
});

it('shows the by-product valuation rate only to accounts', function () {
    signInP5($this, $this->f->inv->supervisor);
    Livewire::test(ManageProducts::class)
        ->mountTableAction('edit', $this->f->inv->bran)
        ->assertFormFieldIsHidden('valuation_paise_per_kg', 'mountedTableActionForm');

    signInP5($this, $this->f->accountant);
    Livewire::test(ManageProducts::class)
        ->callTableAction('edit', $this->f->inv->bran, ['valuation_paise_per_kg' => '15.50']);
    expect($this->f->inv->bran->fresh()->valuation_paise_per_kg)->toBe(1550);
});
