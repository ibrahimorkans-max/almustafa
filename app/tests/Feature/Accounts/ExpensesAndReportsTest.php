<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Accounts\Expenses;
use App\Domain\Accounts\FinancialReports;
use App\Domain\Alerts\HrAnomalies;
use App\Domain\Inventory\StockLedger;
use App\Domain\Reports\FinancialTables;
use App\Domain\Reports\HrDashboardMetrics;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\FinancialReportsPage;
use App\Filament\Resources\Accounts\ExpenseCategoryResource\Pages\ListExpenseCategories;
use App\Filament\Resources\Accounts\ExpenseResource\Pages\CreateExpense;
use App\Filament\Resources\Accounts\ExpenseResource\Pages\ListExpenses;
use App\Filament\Resources\Accounts\RecurringExpenseResource\Pages\CreateRecurringExpense;
use App\Filament\Resources\Accounts\RecurringExpenseResource\Pages\EditRecurringExpense;
use App\Filament\Widgets\HrOverview;
use App\Filament\Widgets\OwnerOverview;
use App\Models\Accounts\Expense;
use App\Models\Accounts\ExpenseBudget;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\ProductCost;
use App\Models\Accounts\RecurringExpense;
use App\Models\Alert;
use App\Models\Documents\DocumentBatch;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Models\Inventory\StockEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Support\Values\Weight;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('PNL'));
    $this->chart = app(ChartOfAccounts::class);
    $this->books = app(Books::class);
    $this->svc = app(Expenses::class);
    $this->f->as($this->f->accountant);
    app(ExpenseCategories::class)->installDefaults();
    $this->cash = $this->chart->system('cash');
    // Last month: every figure below is dated inside it, the opening stock before it.
    $this->m = CarbonImmutable::now('Asia/Karachi')->subMonthNoOverflow()->startOfMonth();
});

function exCategory(string $code): ExpenseCategory
{
    return ExpenseCategory::query()->where('code', $code)->firstOrFail();
}

function exSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    $t->f->as($a);
}

/**
 * Last month's books, small enough to check by hand (paise):
 * opening stock 10,000 kg paddy at 100 = 1,000,000; bought 5,000 kg (purchases 500,000), milled 3,000 kg
 * → closing 12,000 kg at 100 = 1,200,000. Sales 900,000; contract labour 50,000; salaries 200,000;
 * rent 30,000 (an expense entry); scrap sold 10,000 (an income entry).
 * Cost of goods 1,000,000 + 500,000 + 50,000 − 1,200,000 = 350,000; gross 550,000; net 550,000 − 230,000 + 10,000 = 330,000.
 */
function exMonth(object $t): void
{
    $t->f->as($t->f->owner);
    $paddy = Product::query()->create(['code' => 'PADDY-X', 'name' => 'Paddy 1121', 'category' => 'paddy', 'sale_unit' => 'kg']);
    $silo = Godown::query()->create(['code' => 'SX', 'name' => 'Silo X', 'type' => 'silo']);
    $before = $t->m->subDays(5)->setTime(10, 0);
    $stock = app(StockLedger::class);
    $stock->post($paddy, $silo, Weight::kg('10000'), StockEntry::RECEIPT, ['note' => 'opening'], $before->utc());
    ProductCost::query()->create(['product_id' => $paddy->id, 'avg_paise_per_kg' => '100.0000', 'basis_kg' => '10000.000', 'source_type' => 'test',
        'source_id' => (string) Str::uuid(), 'data' => [], 'created_at' => $before->utc()]);
    $stock->post($paddy, $silo, Weight::kg('5000'), StockEntry::RECEIPT, [], $t->m->addDays(3)->setTime(10, 0)->utc());
    $stock->post($paddy, $silo, Weight::kg('-3000'), StockEntry::PRODUCTION_ISSUE, [], $t->m->addDays(6)->setTime(10, 0)->utc());

    $t->f->as($t->f->accountant);
    $day = $t->m->addDays(9);
    $c = fn (string $key) => $t->chart->system($key);
    $t->books->post('payment', $day, [['account' => $c('purchase_paddy'), 'dr' => 500_000], ['account' => $t->cash, 'cr' => 500_000]], 'Paddy');
    $t->books->post('receipt', $day, [['account' => $t->cash, 'dr' => 900_000], ['account' => $c('sales_rice'), 'cr' => 900_000]], 'Rice sold');
    $t->books->post('payment', $day, [['account' => $c('contract_labour'), 'dr' => 50_000], ['account' => $t->cash, 'cr' => 50_000]], 'Loading gang');
    $t->books->post('payment', $day, [['account' => $c('salaries_expense'), 'dr' => 200_000], ['account' => $t->cash, 'cr' => 200_000]], 'Salaries');
    $t->svc->record(exCategory('RENT'), $day, 30_000, 'Godown rent', $t->cash, null, null, true);
    $t->svc->record(exCategory('SCRAP'), $day, 10_000, 'Empty bags sold', $t->cash, null, null, true);
}

describe('expenses and income', function () {
    it('keeps a draft out of the books, posts it with a corrected amount and cancels another', function () {
        $draft = $this->svc->record(exCategory('MEALS'), CarbonImmutable::now('Asia/Karachi'), 1_500_00, 'Tea for the night shift', $this->cash, null);
        expect($draft->status)->toBe(Expense::DRAFT)->and($draft->voucher_id)->toBeNull()
            ->and($this->books->balance(exCategory('MEALS')->ledger))->toBe(0);

        $posted = $this->svc->post($draft, 1_650_00);
        expect($posted->status)->toBe(Expense::POSTED)->and($posted->amount_paise)->toBe(1_650_00)
            ->and($this->books->balance(exCategory('MEALS')->ledger))->toBe(1_650_00)
            ->and($this->books->balance($this->cash))->toBe(-1_650_00)
            ->and(fn () => $this->svc->post($posted))->toThrow(BusinessRuleViolation::class, 'not a draft')
            ->and(fn () => $this->svc->cancel($posted, 'Entered twice'))->toThrow(BusinessRuleViolation::class, 'reverse the voucher');

        $other = $this->svc->record(exCategory('STATIONERY'), CarbonImmutable::now('Asia/Karachi'), 800_00, 'Register books', $this->cash, null);
        expect($this->svc->cancel($other, 'Bought by the owner')->status)->toBe(Expense::CANCELLED)
            ->and($this->books->verify())->toBe([]);
    });

    it('posts income the other way round and an expense on credit against the party', function () {
        $landlord = Party::query()->create(['code' => 'LL1', 'name' => 'Haji Rafiq (landlord)', 'is_vendor' => true]);
        $today = CarbonImmutable::now('Asia/Karachi');
        $this->svc->record(exCategory('SCRAP'), $today, 4_000_00, 'Old bags', $this->cash, null, null, true);
        $this->svc->record(exCategory('RENT'), $today, 25_000_00, 'Godown rent', null, $landlord, null, true);

        expect($this->books->balance(exCategory('SCRAP')->ledger))->toBe(-4_000_00)
            ->and($this->books->balance($this->cash))->toBe(4_000_00)
            ->and($this->books->balance($this->chart->partyLedger($landlord)))->toBe(-25_000_00)
            ->and($this->books->balance(exCategory('RENT')->ledger))->toBe(25_000_00);
    });

    it('refuses entries that cannot be right, and people who do not post vouchers', function () {
        $today = CarbonImmutable::now('Asia/Karachi');
        $party = Party::query()->create(['code' => 'P9', 'name' => 'Someone', 'is_vendor' => true]);
        expect(fn () => $this->svc->record(exCategory('FUEL'), $today, 100_00, 'Diesel', $this->cash, $party))->toThrow(BusinessRuleViolation::class, 'either')
            ->and(fn () => $this->svc->record(exCategory('FUEL'), $today, 100_00, 'Diesel', null, null))->toThrow(BusinessRuleViolation::class, 'either')
            ->and(fn () => $this->svc->record(exCategory('FUEL'), $today->addDays(2), 100_00, 'Diesel', $this->cash, null))->toThrow(BusinessRuleViolation::class, 'future')
            ->and(fn () => $this->svc->record(exCategory('FUEL'), $today, 0, 'Diesel', $this->cash, null))->toThrow(BusinessRuleViolation::class, 'more than zero')
            ->and(fn () => $this->svc->record(exCategory('FUEL'), $today, 100_00, 'Diesel', $this->chart->system('salaries_expense'), null))
            ->toThrow(BusinessRuleViolation::class, 'cash or bank')
            ->and(fn () => $this->svc->record(exCategory('FUEL'), $today, 100_00, 'Diesel', $this->cash, null, 'not an image'))->toThrow(BusinessRuleViolation::class, 'JPEG');

        $this->f->as($this->f->hr);
        expect(fn () => $this->svc->record(exCategory('FUEL'), $today, 100_00, 'Diesel', $this->cash, null))->toThrow(BusinessRuleViolation::class, 'ledger.post')
            ->and(Expense::query()->count())->toBe(0);
    });

    it('drafts each recurring expense once a month on its day, catching up a missed month', function () {
        $start = $this->m->format('Y-m');
        $rent = $this->svc->saveTemplate(null, ['name' => 'Godown rent', 'expense_category_id' => exCategory('RENT')->id, 'amount_paise' => 25_000_00,
            'day_of_month' => 5, 'pay_from_ledger_id' => $this->cash->id, 'starts_month' => $start]);
        $this->svc->saveTemplate(null, ['name' => 'Old guard contract', 'expense_category_id' => exCategory('MISC')->id, 'amount_paise' => 5_000_00,
            'day_of_month' => 1, 'pay_from_ledger_id' => $this->cash->id, 'starts_month' => '2025-01', 'ends_month' => '2025-12']);

        // On the 10th of this month: last month's (missed) and this month's rent are due.
        $tenth = CarbonImmutable::now('Asia/Karachi')->startOfMonth()->addDays(9);
        expect($this->svc->draftRecurring($tenth))->toBe(2)
            ->and($this->svc->draftRecurring($tenth))->toBe(0)
            ->and(Expense::query()->where('recurring_expense_id', $rent->id)->orderBy('recurring_month')->pluck('entry_date')->map->toDateString()->all())
            ->toBe([$this->m->setDay(5)->toDateString(), $tenth->setDay(5)->toDateString()])
            ->and((int) Expense::query()->where('status', Expense::DRAFT)->sum('amount_paise'))->toBe(50_000_00)
            ->and($this->books->balance(exCategory('RENT')->ledger))->toBe(0);

        expect(fn () => $this->svc->saveTemplate(null, ['name' => 'Bad day', 'expense_category_id' => exCategory('RENT')->id, 'amount_paise' => 100,
            'day_of_month' => 31, 'pay_from_ledger_id' => $this->cash->id, 'starts_month' => $start]))->toThrow(BusinessRuleViolation::class, '1 to 28');
    });

    it('sets and changes a monthly budget', function () {
        $this->svc->setBudget(exCategory('FUEL'), '2026-09', 40_000_00);
        $this->svc->setBudget(exCategory('FUEL'), '2026-09', 45_000_00);
        expect(ExpenseBudget::query()->sole()->amount_paise)->toBe(45_000_00)
            ->and(fn () => $this->svc->setBudget(exCategory('FUEL'), 'Sept', 1))->toThrow(BusinessRuleViolation::class, 'YYYY-MM');
    });
});

describe('financial reports', function () {
    it('builds the profit and loss from the books, with stock valued on each date', function () {
        exMonth($this);
        $r = app(FinancialReports::class);
        $pl = $r->profitAndLoss($this->m, $this->m->endOfMonth()->startOfDay());

        expect($pl['opening_stock'])->toBe(1_000_000)
            ->and($pl['closing_stock'])->toBe(1_200_000)
            ->and($pl['sales']['total'])->toBe(900_000)
            ->and($pl['purchases']['total'])->toBe(500_000)
            ->and($pl['direct_expenses']['total'])->toBe(50_000)
            ->and($pl['cost_of_goods'])->toBe(350_000)
            ->and($pl['gross_profit'])->toBe(550_000)
            ->and($pl['indirect_expenses']['total'])->toBe(230_000)
            ->and($pl['payroll_cost'])->toBe(200_000)
            ->and($pl['indirect_incomes']['total'])->toBe(10_000)
            ->and($pl['net_profit'])->toBe(330_000)
            ->and(collect($pl['indirect_expenses']['lines'])->pluck('name')->all())->toContain('Rent', 'Salaries and wages');

        // The month view agrees. The month before shows the opening stock as a gain: it was booked
        // without a purchase bill, and stock in hand at the end of a month lowers its cost of goods.
        $months = $r->monthly($this->m->subMonthNoOverflow(), $this->m->endOfMonth()->startOfDay());
        expect(array_column($months, 'net_profit'))->toBe([1_000_000, 330_000])
            ->and(array_column($months, 'cost_of_goods'))->toBe([-1_000_000, 350_000]);

        // Milling cost: (50,000 + 230,000) over 3,000 kg = 75 maunds.
        expect($r->costPerMaund($this->m, $this->m->endOfMonth()->startOfDay()))->toBe(['expenses' => 280_000, 'maunds' => '75.000', 'per_maund' => 3_733]);
    });

    it('shows cash flow by purpose and income and expenses against budget', function () {
        exMonth($this);
        $this->svc->setBudget(exCategory('RENT'), $this->m->format('Y-m'), 25_000);
        $r = app(FinancialReports::class);

        expect($r->cashFlow($this->m, $this->m->endOfMonth()->startOfDay()))->toBe([
            'opening' => 0,
            'inflows' => ['Received from customers and others' => 900_000, 'Other income' => 10_000],
            'outflows' => ['Other payments' => 750_000, 'Expenses' => 30_000],
            'closing' => 130_000,
        ]);
        $rent = collect($r->budgetVsActual($this->m->format('Y-m')))->firstWhere('code', 'RENT');
        expect($rent)->toMatchArray(['budget' => 25_000, 'actual' => 30_000, 'variance' => 5_000, 'shared_ledger' => false]);
        $scrap = collect($r->budgetVsActual($this->m->format('Y-m')))->firstWhere('code', 'SCRAP');
        expect($scrap)->toMatchArray(['kind' => 'income', 'actual' => 10_000, 'budget' => null, 'variance' => null]);
    });

    it('sums payroll cost by department from finalized payrolls and lists what staff owe and are owed', function () {
        $a = $this->f->employee(['joining_date' => '2026-01-01']);
        $b = $this->f->employee(['joining_date' => '2026-01-01']);
        exPayroll($this, '2026-08', [[$a, 3_000_000, 0], [$b, 2_000_000, 0]]);
        exPayroll($this, '2026-09', [[$a, 3_000_000, 0]], status: PayrollRun::CALCULATED); // not finalized: left out

        $cost = app(FinancialReports::class)->payrollCost('2026-08', '2026-09');
        expect($cost['months'])->toBe(['2026-08'])
            ->and($cost['total'])->toBe(['headcount' => 2, 'gross' => 5_000_000, 'employer' => 500_000, 'cost' => 5_500_000]);

        $this->books->post('journal', CarbonImmutable::now('Asia/Karachi'), [
            ['account' => $this->chart->system('salaries_expense'), 'dr' => 70_000], ['account' => $this->chart->system('salaries_payable'), 'cr' => 70_000],
        ], 'Salaries payable');
        expect(app(FinancialReports::class)->staffBalances(CarbonImmutable::now('Asia/Karachi')))
            ->toMatchArray(['salaries_payable' => 70_000, 'claims_payable' => 0, 'advances_total' => 0]);
    });

    it('renders every report as one table, with money in brackets when negative', function () {
        exMonth($this);
        $tables = app(FinancialTables::class);
        $p = ['from' => $this->m->toDateString(), 'to' => $this->m->endOfMonth()->toDateString(), 'month' => $this->m->format('Y-m')];
        foreach (array_keys(FinancialTables::REPORTS) as $report) {
            $t = $tables->build($report, $p);
            expect($t['rows'])->not->toBeEmpty()->and($t['title'])->not->toBe('');
        }
        $pl = $tables->build('pl', $p);
        expect(collect($pl['rows'])->firstWhere('label', 'Net profit')['cells'])->toBe([330_000])
            ->and(FinancialTables::money(-1_234_50))->toBe('(1,234.50)')
            ->and(collect($pl['rows'])->firstWhere('label', 'Rent'))->toHaveKey('ledger');
    });
});

/** A payroll run and its items written directly (the payroll engine is tested elsewhere). */
function exPayroll(object $t, string $month, array $people, string $status = PayrollRun::FINALIZED, array $info = []): PayrollRun
{
    $run = PayrollRun::query()->create(['month' => $month]);
    $run->forceFill(['status' => $status, 'calculation_no' => 1, 'number' => "PR-{$month}", 'finalized_at' => $status === PayrollRun::FINALIZED ? now() : null])->save();
    foreach ($people as [$e, $gross, $overtime]) {
        /** @var Employee $e */
        PayrollItem::query()->create(['payroll_run_id' => $run->id, 'calculation_no' => 1, 'employee_id' => $e->id,
            'employee' => ['code' => $e->code, 'name' => $e->name(), 'department' => 'Production'],
            'result' => ['info' => ['overtime_minutes' => $overtime] + ($info[$e->id] ?? [])], 'gross_paise' => $gross, 'deductions_paise' => 0,
            'net_paise' => $gross, 'employer_paise' => intdiv($gross, 10), 'created_at' => now()]);
    }

    return $run;
}

describe('payroll and delivery alerts', function () {
    it('raises overtime, payroll swing, minimum wage and failed delivery alerts once each', function () {
        $a = $this->f->employee(['joining_date' => '2026-01-01']);
        $b = $this->f->employee(['joining_date' => '2026-01-01']);
        exPayroll($this, '2026-08', [[$a, 3_000_000, 600], [$b, 2_000_000, 0]]);
        exPayroll($this, '2026-09', [[$a, 3_000_000, 70 * 60], [$b, 3_500_000, 0]], info: [$b->id => ['below_minimum_wage' => 1]]);
        $batch = DocumentBatch::query()->create(['doc_type' => 'payslip', 'label' => 'Payslips 2026-09', 'source_ids' => [], 'options' => [], 'total' => 2]);
        $batch->forceFill(['status' => DocumentBatch::FAILED, 'error' => 'Disk full'])->save();

        $raised = app(HrAnomalies::class)->scan(now()->subDay());
        // Gross 5,000,000 → 6,500,000 is +30 %, above the 15 % default; 70 h of overtime is above 60 h.
        expect($raised)->toBe(['abnormal_overtime' => 1, 'payroll_variance' => 1, 'minimum_wage_shortfall' => 1, 'failed_delivery' => 1])
            ->and(Alert::query()->where('type', 'payroll_variance')->sole()->title)->toBe('Payroll 2026-09 up 30% on 2026-08')
            ->and(app(HrAnomalies::class)->scan(now()->subDay()))->toBe(['abnormal_overtime' => 0, 'payroll_variance' => 0, 'minimum_wage_shortfall' => 0, 'failed_delivery' => 0]);
    });
});

describe('screens', function () {
    beforeEach(function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    });

    it('opens the expense, recurring, budget and profit and loss screens only for accounts and the owner', function () {
        $seen = [];
        foreach (['accountant' => $this->f->accountant, 'owner' => $this->f->owner, 'hr' => $this->f->hr, 'gate' => $this->f->gate] as $who => $a) {
            exSignIn($this, $a);
            $seen[$who] = implode('/', array_map(fn (string $p) => $this->get($p)->status(),
                ['/admin/expenses', '/admin/recurring-expenses', '/admin/profit-and-loss']));
        }
        expect($seen)->toBe(['accountant' => '200/200/200', 'owner' => '200/200/200', 'hr' => '403/403/403', 'gate' => '403/403/403']);
    });

    it('enters an expense on screen, posts a recurring draft from the list and cancels another', function () {
        exSignIn($this, $this->f->accountant);
        Livewire::test(CreateExpense::class)->fillForm([
            'expense_category_id' => exCategory('FUEL')->id, 'entry_date' => CarbonImmutable::now('Asia/Karachi')->toDateString(), 'amount' => '3200',
            'description' => 'Generator diesel', 'via' => 'cash', 'cash_ledger_id' => $this->cash->id, 'post' => true,
        ])->call('create')->assertHasNoFormErrors();
        expect(Expense::query()->sole()->status)->toBe(Expense::POSTED);

        Livewire::test(CreateRecurringExpense::class)->fillForm([
            'name' => 'Electricity (WAPDA)', 'expense_category_id' => exCategory('ELECTRICITY')->id, 'amount' => '85000', 'day_of_month' => 1,
            'starts_month' => CarbonImmutable::now('Asia/Karachi')->format('Y-m'), 'via' => 'cash', 'pay_from_ledger_id' => $this->cash->id, 'active' => true,
        ])->call('create')->assertHasNoFormErrors();
        $template = RecurringExpense::query()->sole();
        Livewire::test(EditRecurringExpense::class, ['record' => $template->id])->assertFormSet(['amount' => '85000.00', 'via' => 'cash'])
            ->fillForm(['amount' => '90000'])->call('save')->assertHasNoFormErrors();
        expect($template->fresh()->amount_paise)->toBe(90_000_00);

        Livewire::test(ListExpenses::class)->callAction('draft')->assertNotified('1 draft(s) made; post them after checking the amounts.');
        $draft = Expense::query()->where('status', Expense::DRAFT)->sole();
        Livewire::test(ListExpenses::class)
            ->callTableAction('post', $draft->id, ['amount' => '87500.50', 'via' => 'cash', 'cash_ledger_id' => $this->cash->id])->assertNotified('Posted.');
        expect($draft->fresh()->amount_paise)->toBe(87_500_50)->and($this->books->balance(exCategory('ELECTRICITY')->ledger))->toBe(87_500_50);

        $other = $this->svc->record(exCategory('MEALS'), CarbonImmutable::now('Asia/Karachi'), 500_00, 'Tea', $this->cash, null);
        Livewire::test(ListExpenses::class)->callTableAction('cancel', $other->id, ['reason' => 'Paid by the owner'])->assertNotified('Draft cancelled.');
        expect($other->fresh()->status)->toBe(Expense::CANCELLED);

        Livewire::test(ListExpenseCategories::class)->callTableAction('budget', exCategory('FUEL')->id, ['month' => '2026-11', 'amount' => '40000'])
            ->assertNotified('Budget for 2026-11 saved.');
        expect(ExpenseBudget::query()->sole()->amount_paise)->toBe(40_000_00);
    });

    it('shows the profit and loss with links to the ledgers, and downloads it as PDF and Excel', function () {
        exMonth($this);
        exSignIn($this, $this->f->accountant);
        $page = Livewire::test(FinancialReportsPage::class)
            ->fillForm(['report' => 'pl', 'from' => $this->m->toDateString(), 'to' => $this->m->endOfMonth()->toDateString()])
            ->assertSee('Net profit')->assertSee('3,300.00')->assertSee('Rent')->assertSee('report=ledger');
        $page->callAction('pdf')->assertFileDownloaded();
        $page->callAction('excel')->assertFileDownloaded();
        $page->fillForm(['report' => 'cashflow'])->assertSee('Cash and bank at the end')->assertSee('1,300.00');
        expect(Alert::query()->count())->toBe(0);
    });

    it('shows the owner and HR their dashboard figures', function () {
        $this->f->employee(['joining_date' => '2026-01-01']);
        $metrics = app(HrDashboardMetrics::class);
        expect($metrics->today()['headcount'])->toBe(1)
            ->and($metrics->hr())->toHaveKeys(['flagged_punches', 'leave_pending', 'payroll_month', 'undelivered_payslips'])
            ->and($metrics->owner())->toHaveKeys(['payroll_cost', 'net_profit', 'cost_per_maund']);

        exSignIn($this, $this->f->owner);
        $this->get(Dashboard::getUrl())->assertOk();
        expect(OwnerOverview::canView())->toBeTrue()->and(HrOverview::canView())->toBeTrue();
        Livewire::test(OwnerOverview::class)->assertSee('Net profit so far')->assertSee('Milling cost per maund');
        Livewire::test(HrOverview::class)->assertSee('Present today')->assertSee('0 of 1')->assertSee('Payslips not delivered');

        exSignIn($this, $this->f->hr);
        $this->get(Dashboard::getUrl())->assertOk();
        expect(OwnerOverview::canView())->toBeFalse()->and(HrOverview::canView())->toBeTrue();
        exSignIn($this, $this->f->gate);
        expect(HrOverview::canView())->toBeFalse();
    });
});
