<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\PurchaseBillCalculator;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Approvals\Actions\VoucherReversal;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Gate\GateService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\PurchaseBill;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherLine;
use App\Models\ApprovalRequest;
use Tests\Support\AccountsFixtures;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('PBL'));
});

it('computes bill amounts in paise with half-up rounding', function () {
    $calc = new PurchaseBillCalculator;

    expect($calc->calculate('14781.250', 250000, 1000, 1000, true))->toBe([
        'gross_paise' => 36_953_125, 'rate_cut_paise' => 147_813, 'brokerage_paise' => 147_813,
        'round_off_paise' => -12, 'net_paise' => 36_805_300,
    ])
        ->and($calc->calculate('100', 250000, 0, 0, false)['net_paise'])->toBe(250_000)
        ->and(PurchaseBillCalculator::perQuintal('0.001', 150000))->toBe(2)   // 1.5 paise → 2 (half-up)
        ->and(PurchaseBillCalculator::perQuintal('0.001', 140000))->toBe(1)   // 1.4 paise → 1
        ->and($calc->calculate('10.010', 123456, 0, 0, true)['round_off_paise'])->toBe(42); // 12,357.9 paise → 12,358 → ₹124
});

it('drafts the bill from the slip and the frozen sauda terms when the slip is issued', function () {
    $bill = $this->f->billFor($this->f->receiveLot());

    expect($bill->status)->toBe('draft')
        ->and($bill->payable_kg)->toBe('14781.250')
        ->and($bill->rate_paise_per_quintal)->toBe(250000)
        ->and($bill->gross_paise)->toBe(36_953_125)
        ->and($bill->rate_cut_paise)->toBe(147_813)
        ->and($bill->brokerage_paise)->toBe(147_813)
        ->and($bill->net_paise)->toBe(36_805_300)
        ->and($bill->due_date->diffInDays($bill->bill_date, true))->toEqual(15)
        ->and($bill->snapshot['slip']['net_kg'])->toBe('15250.000')
        ->and(Voucher::query()->count())->toBe(0);

    // Idempotent: asking again returns the same draft.
    $this->f->as($this->f->accountant);
    expect(app(PurchaseBillService::class)->draftFromSlip($bill->gateEntry)->id)->toBe($bill->id);
});

it('posts a balanced purchase voucher with party, rate cut, round-off and brokerage', function () {
    $bill = $this->f->billFor($this->f->receiveLot());
    $this->f->as($this->f->accountant);
    $posted = app(PurchaseBillService::class)->post($bill, 'Checked against slip');

    $lines = VoucherLine::query()->where('voucher_id', $posted->voucher_id)->with('account')->orderBy('line_no')->get()
        ->map(fn (VoucherLine $l) => [$l->account->code, $l->dr_paise, $l->cr_paise])->all();
    expect($posted->status)->toBe('posted')->and($posted->number)->toContain('/PV/')
        ->and($lines)->toBe([
            ['PUR-PADDY', 36_953_125, 0],
            ['PUR-RATECUT', 0, 147_813],
            ['P-F1', 0, 36_805_300],
            ['EXP-ROUNDOFF', 0, 12],
            ['EXP-BROKERAGE', 147_813, 0],
            ['P-B1', 0, 147_813],
        ])
        ->and(app(Books::class)->balance(app(ChartOfAccounts::class)->partyLedger($this->f->inv->gate->seller)))->toBe(-36_805_300)
        ->and(VoucherLine::query()->where('bill_id', $bill->id)->count())->toBe(1);

    expect(fn () => app(PurchaseBillService::class)->post($bill, null))->toThrow(BusinessRuleViolation::class, 'posted');
});

it('lets only accounts post or cancel drafts', function () {
    $bill = $this->f->billFor($this->f->receiveLot());
    $this->f->as($this->f->inv->supervisor);
    expect(fn () => app(PurchaseBillService::class)->post($bill))->toThrow(BusinessRuleViolation::class, 'ledger.post');

    $this->f->as($this->f->accountant);
    app(PurchaseBillService::class)->cancelDraft($bill, 'Slip issued against the wrong sauda');
    expect($bill->fresh()->status)->toBe('cancelled');
    expect(fn () => app(PurchaseBillService::class)->post($bill))->toThrow(BusinessRuleViolation::class, 'cancelled');
});

it('reverses a posted bill only through a second person, keeping the original voucher', function () {
    $bill = $this->f->billFor($this->f->receiveLot());
    $this->f->as($this->f->accountant);
    $bill = app(PurchaseBillService::class)->post($bill);

    $request = app(ApprovalService::class)->submit(VoucherReversal::KEY, ['voucher_id' => $bill->voucher_id, 'reason' => 'Wrong rate on the sauda'], 'Reverse');
    expect(app(ApprovalService::class)->canDecide($this->f->accountant, $request))->toBeFalse();
    app(ApprovalService::class)->approve($request, $this->f->owner);

    expect($request->fresh()->status)->toBe(ApprovalRequest::STATUS_EXECUTED)
        ->and($bill->fresh()->status)->toBe(PurchaseBill::CANCELLED)
        ->and(Voucher::query()->count())->toBe(2)
        ->and(app(Books::class)->balance(app(ChartOfAccounts::class)->partyLedger($this->f->inv->gate->seller)))->toBe(0);
});

it('does not bill rejected lots or slips without a sauda', function () {
    $this->f->as($this->f->inv->supervisor);
    $g = app(GateService::class);
    $e = $g->arrive(['registration_no' => 'HR05AB1234', 'party_id' => $this->f->inv->gate->seller->id]);
    $g->recordWeighment($e, 'gross', '20000', $this->f->inv->gate->scale, GateFixtures::readings('20000'), str_repeat('b', 64));
    $g->recordLabTest($e, ['moisture_bp' => 1600]);
    $g->decideLab($e, true);
    $g->markUnloaded($e, godown: $this->f->inv->silo);
    $g->recordWeighment($e, 'tare', '9000', $this->f->inv->gate->scale, GateFixtures::readings('9000'), str_repeat('b', 64));
    $g->issueSlip($e);

    expect(PurchaseBill::query()->count())->toBe(0);
});
