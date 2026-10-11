<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Hr\ContractorBilling;
use App\Domain\Hr\Payroll\PieceWorkService;
use App\Domain\Hr\Reimbursements;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\ContractorBill;
use App\Models\Hr\Employee;
use App\Models\Hr\Reimbursement;
use App\Models\Hr\Shift;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('CLM'));
    $this->e = $this->f->employee(['joining_date' => '2026-01-01']);
    $this->chart = app(ChartOfAccounts::class);
    $this->books = app(Books::class);
    $this->claims = app(Reimbursements::class);
});

/** A 1×1 PNG, enough for the file store's type check. */
function ccReceipt(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

function ccCategory(string $code): string
{
    app(ExpenseCategories::class)->installDefaults();

    return ExpenseCategory::query()->where('code', $code)->value('id');
}

/** Valid in / out punches 09:00–18:00 on the given days. */
function ccPunch(Employee $e, array $days): void
{
    foreach ($days as $day) {
        foreach ([['in', '09:00'], ['out', '18:00']] as [$kind, $time]) {
            $at = CarbonImmutable::parse($day.' '.$time, 'Asia/Karachi')->utc()->format('Y-m-d H:i:s.v');
            AttendanceLog::query()->create(['employee_id' => $e->id, 'kind' => $kind, 'source' => 'app', 'occurred_at' => $at, 'received_at' => $at,
                'time_check' => 'verified', 'client_event_id' => (string) Str::uuid(), 'status' => 'valid', 'reasons' => [], 'created_at' => now()]);
        }
    }
}

describe('staff expense claims', function () {
    it('goes submitted → checked → approved → paid, posting the expense and then the payment', function () {
        $this->f->as($this->f->hr);
        $today = CarbonImmutable::now('Asia/Karachi');
        $claim = $this->claims->submit($this->e, 'Trip to Multan for spare parts', [
            ['category_id' => ccCategory('TRAVEL'), 'spent_on' => $today->subDays(3)->toDateString(), 'description' => 'Bus fare', 'amount_paise' => 400_00],
            ['category_id' => ccCategory('REPAIRS'), 'spent_on' => $today->subDays(3)->toDateString(), 'description' => 'Bearing', 'amount_paise' => 2_500_00, 'receipt' => ccReceipt()],
        ]);
        expect($claim->status)->toBe(Reimbursement::SUBMITTED)->and($claim->total_paise)->toBe(2_900_00);

        // HR submitted it, so HR cannot decide; accounts cannot approve before the supervisor checks.
        $this->f->as($this->f->accountant);
        expect(fn () => $this->claims->approve($claim))->toThrow(BusinessRuleViolation::class);

        $this->f->as($this->f->supervisor);
        $claim = $this->claims->check($claim);
        expect($claim->status)->toBe(Reimbursement::CHECKED);

        $this->f->as($this->f->accountant);
        $claim = $this->claims->approve($claim);
        $payable = $this->chart->system('staff_claims_payable');
        expect($claim->status)->toBe(Reimbursement::APPROVED)
            ->and($this->books->balance($payable))->toBe(-2_900_00)
            ->and($this->books->balance(ExpenseCategory::query()->where('code', 'REPAIRS')->first()->ledger))->toBe(2_500_00);

        $claim = $this->claims->pay($claim, $this->chart->system('cash'), $today);
        expect($claim->status)->toBe(Reimbursement::PAID)
            ->and($this->books->balance($payable))->toBe(0)
            ->and($this->books->verify())->toBe([]);
        expect(fn () => $this->claims->pay($claim, $this->chart->system('cash'), $today))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses bad claims: no receipt above the limit, old dates, other people, non-claimable categories', function () {
        $this->f->as($this->f->hr);
        $today = CarbonImmutable::now('Asia/Karachi')->toDateString();
        $line = ['category_id' => ccCategory('FUEL'), 'spent_on' => $today, 'description' => 'Diesel', 'amount_paise' => 1_000_00];

        expect(fn () => $this->claims->submit($this->e, 'Generator diesel', [$line]))->toThrow(BusinessRuleViolation::class, 'attach the receipt')
            ->and(fn () => $this->claims->submit($this->e, 'Generator diesel', [['spent_on' => CarbonImmutable::now()->subDays(120)->toDateString(), 'receipt' => ccReceipt()] + $line]))
            ->toThrow(BusinessRuleViolation::class, 'last 90 days')
            ->and(fn () => $this->claims->submit($this->e, 'Electricity bill', [['category_id' => ccCategory('ELECTRICITY'), 'receipt' => ccReceipt()] + $line]))
            ->toThrow(BusinessRuleViolation::class, 'cannot be claimed')
            ->and(fn () => $this->claims->submit($this->e, 'Generator diesel', [['receipt' => 'plain text, not a receipt'] + $line]))
            ->toThrow(BusinessRuleViolation::class, 'JPEG, PNG');

        $this->f->as($this->f->gate);
        expect(fn () => $this->claims->submit($this->e, 'Generator diesel', [['receipt' => ccReceipt()] + $line]))->toThrow(BusinessRuleViolation::class, 'only for yourself');
        expect(Reimbursement::query()->count())->toBe(0);
    });

    it('lets a supervisor refuse a claim with a reason, after which it cannot move on', function () {
        $this->f->as($this->f->hr);
        $claim = $this->claims->submit($this->e, 'Tea for the night shift', [
            ['category_id' => ccCategory('MEALS'), 'spent_on' => CarbonImmutable::now('Asia/Karachi')->toDateString(), 'description' => 'Tea', 'amount_paise' => 300_00],
        ]);
        $this->f->as($this->f->supervisor);
        expect(fn () => $this->claims->reject($claim, 'no'))->toThrow(BusinessRuleViolation::class);
        $claim = $this->claims->reject($claim, 'Not approved in advance');
        expect($claim->status)->toBe(Reimbursement::REJECTED)
            ->and(fn () => $this->claims->check($claim))->toThrow(BusinessRuleViolation::class);
    });

    it('allows only an expense ledger for an expense category', function () {
        $this->f->as($this->f->accountant);
        expect(fn () => app(ExpenseCategories::class)->create('BAD', 'Bad', 'expense', $this->chart->system('cash'), true))
            ->toThrow(BusinessRuleViolation::class);
        ccCategory('MISC');
        $cat = app(ExpenseCategories::class)->create('guard', 'Guard expenses', 'expense', ExpenseCategory::query()->where('code', 'MISC')->first()->ledger, true);
        expect($cat->code)->toBe('GUARD');
    });
});

describe('labour contractor bills', function () {
    beforeEach(function () {
        $this->f->as($this->f->owner);
        $this->contractor = Party::query()->create(['code' => 'LC1', 'name' => 'Rafiq Labour Contractor', 'is_vendor' => true]);
        $gen = Shift::query()->where('code', 'GEN')->value('id');
        $this->w1 = $this->f->employee(['joining_date' => '2026-01-01', 'employment_type' => 'contract']);
        $this->w2 = $this->f->employee(['joining_date' => '2026-01-01', 'employment_type' => 'contract']);
        foreach ([$this->w1, $this->w2] as $w) {
            $w->forceFill(['shift_id' => $gen])->save();
        }
        $this->bills = app(ContractorBilling::class);
        $this->f->as($this->f->hr);
        expect($this->bills->assignWorkers($this->contractor, [$this->w1->id, $this->w2->id]))->toBe(2);
        // A finished week in the past: Monday to Saturday.
        $this->from = CarbonImmutable::now('Asia/Karachi')->subWeeks(2)->startOfWeek();
        $this->to = $this->from->addDays(5);
    });

    it('bills man-days from attendance, withholds tax, and posts on approval by a second person', function () {
        ccPunch($this->w1, [$this->from->toDateString(), $this->from->addDay()->toDateString(), $this->from->addDays(2)->toDateString()]);
        ccPunch($this->w2, [$this->from->toDateString()]);

        $bill = $this->bills->prepare($this->contractor, $this->from, $this->to, 'man_days', 1_200_00, 0, 1000);
        // 4 man-days × Rs 1,200 = Rs 4,800; 10 % withheld → Rs 4,320 payable.
        expect($bill->gross_paise)->toBe(4_800_00)
            ->and($bill->wht_paise)->toBe(480_00)
            ->and($bill->payable_paise)->toBe(4_320_00)
            ->and($bill->lines()->count())->toBe(2);

        expect(fn () => $this->bills->prepare($this->contractor, $this->from, $this->to, 'man_days', 1_200_00))
            ->toThrow(BusinessRuleViolation::class, 'already covers');

        $this->f->as($this->f->accountant);
        $bill = $this->bills->approve($bill);
        expect($bill->status)->toBe('approved')
            ->and($this->books->balance($this->chart->system('contract_labour')))->toBe(4_800_00)
            ->and($this->books->balance($this->chart->partyLedger($this->contractor)))->toBe(-4_320_00)
            ->and($this->books->balance($this->chart->system('wht_payable')))->toBe(-480_00)
            ->and($this->books->verify())->toBe([])
            ->and(fn () => $this->bills->approve($bill))->toThrow(BusinessRuleViolation::class);
    });

    it('bills piece work per unit and refuses a missing rate', function () {
        app(PieceWorkService::class)->record([$this->w1->id, $this->w2->id], $this->from->addDay(), 'bag', '500');

        expect(fn () => $this->bills->prepare($this->contractor, $this->from, $this->to, 'piece', ['maund' => 10_00]))
            ->toThrow(BusinessRuleViolation::class, 'rate per bag');
        $bill = $this->bills->prepare($this->contractor, $this->from, $this->to, 'piece', ['bag' => 5_00], 1600);
        expect($bill->gross_paise)->toBe(2_500_00)            // 500 bags × Rs 5
            ->and($bill->sales_tax_paise)->toBe(400_00)       // 16 % provincial sales tax
            ->and($bill->payable_paise)->toBe(2_900_00);
    });

    it('refuses unfinished periods, non-vendors, empty periods and approval by the preparer', function () {
        $today = CarbonImmutable::now('Asia/Karachi');
        $customer = Party::query()->create(['code' => 'C9', 'name' => 'Buyer', 'is_customer' => true]);
        expect(fn () => $this->bills->prepare($this->contractor, $today->subDays(3), $today, 'man_days', 1_000_00))->toThrow(BusinessRuleViolation::class, 'already over')
            ->and(fn () => $this->bills->prepare($customer, $this->from, $this->to, 'man_days', 1_000_00))->toThrow(BusinessRuleViolation::class, 'vendor')
            ->and(fn () => $this->bills->prepare($this->contractor, $this->from, $this->to, 'man_days', 1_000_00))->toThrow(BusinessRuleViolation::class, 'no attendance');

        ccPunch($this->w1, [$this->from->toDateString()]);
        $this->f->as($this->f->owner);
        $bill = $this->bills->prepare($this->contractor, $this->from, $this->to, 'man_days', 1_000_00);
        expect(fn () => $this->bills->approve($bill))->toThrow(BusinessRuleViolation::class, 'different person');
        $this->f->as($this->f->accountant);
        expect($this->bills->reject($bill, 'Rate not agreed')->status)->toBe('rejected')
            ->and(ContractorBill::query()->where('status', 'approved')->count())->toBe(0);
    });
});
