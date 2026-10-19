<?php

declare(strict_types=1);

use App\Domain\Accounts\BankFiles;
use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\PaymentService;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\Payment;
use App\Models\Accounts\VoucherLine;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use App\Models\Masters\PartyBankAccount;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('PAY'));
    $entry = $this->f->receiveLot();
    $this->f->as($this->f->accountant);
    $this->bill = app(PurchaseBillService::class)->post($this->f->billFor($entry));
    $this->f->as($this->f->accountant);
    $this->seller = $this->f->inv->gate->seller;
});

function requestPayment(object $t, int $paise, array $alloc = [], string $mode = 'ibft', $from = null): Payment
{
    $t->f->as($t->f->accountant);

    return app(PaymentService::class)->request($t->seller, $mode, $from ?? $t->f->bank, $paise, now(), $alloc);
}

it('pays a bill to the approved bank account after a different person releases it', function () {
    $payment = requestPayment($this, 36_805_300, [$this->bill->id => 36_805_300]);

    expect($payment->status)->toBe('pending')
        ->and($payment->payee)->toMatchArray(['holder' => 'RAM SINGH', 'bank_code' => 'HABB', 'last4' => '1234'])
        ->and($payment->number)->toContain('/PMT/');
    $request = ApprovalRequest::query()->findOrFail($payment->approval_request_id);
    expect(app(ApprovalService::class)->canDecide($this->f->accountant, $request))->toBeFalse();

    app(ApprovalService::class)->approve($request, $this->f->owner);
    $payment->refresh();
    $lines = VoucherLine::query()->where('voucher_id', $payment->voucher_id)->orderBy('line_no')->get();

    expect($payment->status)->toBe('approved')
        ->and($lines->map(fn ($l) => [$l->dr_paise, $l->cr_paise, $l->bill_id])->all())->toBe([
            [36_805_300, 0, $this->bill->id],
            [0, 36_805_300, null],
        ])
        ->and(app(Books::class)->balance(app(ChartOfAccounts::class)->partyLedger($this->seller)))->toBe(0)
        ->and(app(Books::class)->balance($this->f->bank))->toBe(-36_805_300)
        ->and(app(PurchaseBillService::class)->outstanding($this->bill->fresh()))->toBe(0);
});

it('freezes the payee: a later bank-account change does not redirect the payment', function () {
    $payment = requestPayment($this, 10_000_00, [$this->bill->id => 10_000_00]);
    $this->f->sellerBank->forceFill(['account_number' => PakistanIds::ibanFor('MEZN', '0000009999999999')])->save();

    expect($payment->fresh()->payee_account_number)->toBe(PakistanIds::ibanFor('HABB', '0012345678901234'))
        ->and($payment->fresh()->payee['bank_code'])->toBe('HABB');
});

it('refuses bank payments without an approved account, over-payment and over-allocation', function () {
    PartyBankAccount::query()->update(['status' => 'inactive']);
    expect(fn () => requestPayment($this, 100_00))->toThrow(BusinessRuleViolation::class, 'no approved bank account');
    PartyBankAccount::query()->update(['status' => 'active']);

    expect(fn () => requestPayment($this, 40_000_000, [$this->bill->id => 36_805_301]))->toThrow(BusinessRuleViolation::class, 'outstanding');
    expect(fn () => requestPayment($this, 100_00, [$this->bill->id => 200_00]))->toThrow(BusinessRuleViolation::class, 'more than the payment');

    // Money already requested is reserved: a second request cannot pay the same bill again.
    requestPayment($this, 30_000_000, [$this->bill->id => 30_000_000]);
    expect(fn () => requestPayment($this, 10_000_000, [$this->bill->id => 10_000_000]))->toThrow(BusinessRuleViolation::class, 'outstanding');
});

it('needs two different approvers above the limit', function () {
    config(['ricemill.accounts.second_approval_above_paise' => 1_00_000_00]); // Rs 100,000
    // Staff accountants who may also approve payments: the requester and the first approver are excluded.
    $staffApprover = function (): LoginAccount {
        $a = Make::account($this->f->entity, 'ACCOUNTANT');
        Make::system(fn () => DB::table('role_permissions')->insertOrIgnore(array_map(fn (string $p) => [
            'role_id' => $a->role_id, 'permission_code' => $p, 'entity_id' => $this->f->entity->id, 'created_at' => now(),
        ], ['payment.approve', 'approval.decide'])));
        $a->role->flushPermissionCache();

        return $a;
    };
    $requester = $staffApprover();
    $first = $staffApprover();
    $this->f->as($requester);
    $payment = app(PaymentService::class)->request($this->seller, 'ibft', $this->f->bank, 36_805_300, now(), [$this->bill->id => 36_805_300]);
    $approvals = app(ApprovalService::class);

    $result = $approvals->approve(ApprovalRequest::query()->findOrFail($payment->approval_request_id), $first);
    $payment->refresh();
    $second = ApprovalRequest::query()->findOrFail($payment->approval_request_id);

    expect($result->status)->toBe('executed')->and($payment->status)->toBe('first_approved')->and($payment->voucher_id)->toBeNull()
        ->and($approvals->canDecide($first, $second))->toBeFalse()       // first approver
        ->and($approvals->canDecide($requester, $second))->toBeFalse()   // requester
        ->and($approvals->canDecide($this->f->owner, $second))->toBeTrue();

    $approvals->approve($second, $this->f->owner);
    expect($payment->fresh()->status)->toBe('approved')->and($payment->fresh()->voucher_id)->not->toBeNull();

    // The mill's Super Admin needs no second person: their payment is released at once, both stages.
    $this->f->as($this->f->owner2);
    $direct = app(PaymentService::class)->request($this->seller, 'ibft', $this->f->bank, 20_000_000, now(), []);
    expect($direct->fresh()->status)->toBe('approved');
});

it('limits cash to one person per day (s. 21(l))', function () {
    $cash = app(ChartOfAccounts::class)->system('cash');
    requestPayment($this, 150_000_00, mode: 'cash', from: $cash);

    expect(fn () => requestPayment($this, 100_000_01, mode: 'cash', from: $cash))->toThrow(BusinessRuleViolation::class, '21(l)');
    expect(fn () => requestPayment($this, 100_00, mode: 'cash'))->toThrow(BusinessRuleViolation::class, 'cash ledger');
    expect(fn () => requestPayment($this, 100_00, mode: 'ibft', from: $cash))->toThrow(BusinessRuleViolation::class, 'bank accounts');
});

it('frees the bill again when a payment is rejected', function () {
    $payment = requestPayment($this, 36_805_300, [$this->bill->id => 36_805_300]);
    app(ApprovalService::class)->reject(ApprovalRequest::query()->findOrFail($payment->approval_request_id), $this->f->owner, 'Hold until quality claim is settled');

    expect($payment->fresh()->status)->toBe('rejected')
        ->and(app(PurchaseBillService::class)->outstanding($this->bill->fresh()))->toBe(36_805_300);
});

it('builds a bank file once per payment, audits downloads and detects edits', function () {
    foreach ([20_000_000, 16_805_300] as $amount) {
        $p = requestPayment($this, $amount, [$this->bill->id => $amount]);
        app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($p->approval_request_id), $this->f->owner);
    }

    $this->f->as($this->f->owner);
    $files = app(BankFiles::class);
    $file = $files->create($this->f->bank);
    expect(fn () => $files->create($this->f->bank))->toThrow(BusinessRuleViolation::class, 'No released');

    $csv = $files->csv($file);
    $rows = array_map('str_getcsv', explode("\n", trim($csv)));
    expect($file->payment_count)->toBe(2)->and($file->total_paise)->toBe(36_805_300)
        ->and($rows[0])->toBe(BankFiles::HEADER)
        ->and($rows[1])->toMatchArray([1 => 'IBFT', 2 => '200000.00', 3 => 'RAM SINGH', 4 => PakistanIds::ibanFor('HABB', '0012345678901234'), 5 => 'HABB', 6 => 'PK36SCBL0000001123456702'])
        ->and(AuditLog::query()->where('action', 'bank_file.downloaded')->count())->toBe(1);

    // Someone edits a frozen payee in the database: the file no longer matches what was approved.
    DB::table('payments')->where('bank_file_id', $file->id)->limit(1)->update(['payee' => json_encode(['holder' => 'MALLORY', 'bank_code' => 'HABB', 'last4' => '1234', 'bank_name' => null])]);
    expect(fn () => $files->csv($file->fresh()))->toThrow(BusinessRuleViolation::class, 'no longer matches');

    $this->f->as($this->f->inv->supervisor);
    expect(fn () => $files->csv($file))->toThrow(BusinessRuleViolation::class, 'bank.view');
});
