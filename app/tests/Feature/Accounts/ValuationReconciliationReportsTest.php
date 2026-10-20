<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountReports;
use App\Domain\Accounts\BankReconciliation;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\ManualVouchers;
use App\Domain\Accounts\PaymentService;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Accounts\StockValuation;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\BankStatementLine;
use App\Models\Accounts\Payment;
use App\Models\Accounts\ProductCost;
use App\Models\Accounts\VoucherLine;
use App\Models\ApprovalRequest;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('VRR'));
    $this->entry = $this->f->receiveLot();
    $this->f->as($this->f->accountant);
    $this->bill = app(PurchaseBillService::class)->post($this->f->billFor($this->entry));
});

function releasePayment(object $t, int $paise): Payment
{
    $t->f->as($t->f->accountant);
    $p = app(PaymentService::class)->request($t->f->inv->gate->seller, 'ibft', $t->f->bank, $paise, now(), [$t->bill->id => $paise]);
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($p->approval_request_id), $t->f->owner);
    $t->f->as($t->f->accountant);

    return $p->fresh();
}

it('costs paddy at the weighted average of what was received', function () {
    // (gross − rate cut + brokerage) / kg received (the payable weight after the Caat settlement) = 36,953,125 / 14,781.25
    expect(app(StockValuation::class)->averageOf($this->f->inv->paddy->id))->toBe('2500.0000')
        ->and(ProductCost::query()->sole()->source_type)->toBe('purchase_bill');

    $rows = app(StockValuation::class)->stockValue();
    expect($rows)->toHaveCount(1)->and($rows[0]['value_paise'])->toBe(36_953_125); // 14,781.25 × 2,500: stock is worth what was paid
});

it('carries cost through milling: by-products at realisable value, the rest to rice', function () {
    $this->f->as($this->f->owner);
    foreach ([[$this->f->inv->broken, 2000], [$this->f->inv->bran, 1500], [$this->f->inv->husk, 300]] as [$p, $rate]) {
        $p->forceFill(['valuation_paise_per_kg' => $rate])->save();
    }
    $this->f->as($this->f->inv->supervisor);
    $production = app(ProductionService::class);
    $batch = $production->open($this->f->inv->paddy, openedAt: now()->subHours(3));
    [$s, $e] = [now()->subHours(2), now()->subHour()];
    $production->recordInput($batch, $this->f->inv->silo, '10000', $s, $e);
    foreach ([[$this->f->inv->rice, '6500'], [$this->f->inv->broken, '500'], [$this->f->inv->bran, '700'], [$this->f->inv->husk, '2000']] as [$p, $kg]) {
        $production->recordOutput($batch, $p, $this->f->inv->store, $kg, $s, $e);
    }
    $production->close($batch);

    $valuation = app(StockValuation::class);
    // input 10,000 × 2,500 = 25,000,000; by-products 2,650,000; rice gets 22,350,000 / 6,500 kg
    expect($valuation->averageOf($this->f->inv->rice->id))->toBe('3438.4615')
        ->and($valuation->averageOf($this->f->inv->bran->id))->toBe('1500.0000')
        ->and($valuation->averageOf($this->f->inv->paddy->id))->toBe('2500.0000');
});

it('matches statement lines to payments by transaction reference and amount, and skips re-imported rows', function () {
    $p1 = releasePayment($this, 20_000_000);
    $p2 = releasePayment($this, 10_000_000);
    app(PaymentService::class)->recordReference($p1, 'txn0001');
    app(ManualVouchers::class)->receipt($this->f->inv->gate->seller, $this->f->bank, 5_000_00, now(), 'Advance returned');

    $today = now('Asia/Karachi')->format('d/m/Y');
    $csv = "Date,Description,Reference,Debit,Credit\n"
        ."{$today},IBFT TXN0001 RAM SINGH,,\"200,000.00\",\n"
        ."{$today},IBFT RAM SINGH,,\"100,000.00\",\n"
        ."{$today},BY CLG RAM SINGH,,,5000.00\n"
        ."{$today},BANK CHARGES,,17.70,\n";
    $recon = app(BankReconciliation::class);
    $result = $recon->import($this->f->bank, $csv);

    expect($result)->toBe(['imported' => 4, 'duplicates' => 0, 'matched' => 3])
        ->and(BankStatementLine::query()->whereNull('voucher_line_id')->sole()->description)->toBe('BANK CHARGES')
        ->and($recon->import($this->f->bank, $csv))->toBe(['imported' => 0, 'duplicates' => 4, 'matched' => 0]);

    $charges = BankStatementLine::query()->whereNull('voucher_line_id')->sole();
    $other = VoucherLine::query()->where('voucher_id', $p2->voucher_id)->where('cr_paise', '>', 0)->sole();
    expect(fn () => $recon->match($charges, $other))->toThrow(BusinessRuleViolation::class, 'same amount');
    expect(fn () => $recon->import($this->f->bank, "Date,Description,Debit,Credit\n31/02/2026,X,1,\n"))->toThrow(BusinessRuleViolation::class, 'date');
});

it('produces a balanced trial balance, a running ledger and payables ageing', function () {
    releasePayment($this, 20_000_000);
    $reports = app(AccountReports::class);

    $tb = $reports->trialBalance(now());
    expect($tb['total_dr'])->toBe($tb['total_cr'])->and($tb['total_dr'])->toBeGreaterThan(0);

    $party = app(ChartOfAccounts::class)->partyLedger($this->f->inv->gate->seller);
    $statement = $reports->ledgerStatement($party, now()->subDay(), now()->addDay());
    expect($statement['opening'])->toBe(0)
        ->and(array_column($statement['lines'], 'balance'))->toBe([-36_805_300, -16_805_300])
        ->and($statement['closing'])->toBe(-16_805_300);

    $ageing = $reports->payablesAgeing(now()->addDays(40)); // due date + 25
    expect($ageing)->toHaveCount(1)
        ->and($ageing[0])->toMatchArray(['paid' => 20_000_000, 'outstanding' => 16_805_300, 'bucket' => '0–30', 'days_overdue' => 25]);
    expect($reports->payablesAgeing(now())[0]['bucket'])->toBe('not due');
    expect($reports->dayBook(now()))->toHaveCount(2);
});
