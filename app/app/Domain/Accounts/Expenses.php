<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\Expense;
use App\Models\Accounts\ExpenseBudget;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\RecurringExpense;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Expenses and other income entered against a category, by accounts (`ledger.post`). An entry is
 * paid from (or received into) cash or bank, or is on credit with a party. A draft changes nothing
 * in the books; posting makes the voucher:
 * * expense: Dr the category's ledger / Cr cash-bank or the party;
 * * income: Dr cash-bank or the party / Cr the category's ledger.
 * A draft can be cancelled; a posted entry is corrected by reversing its voucher (with approval).
 * Recurring templates draft their entry on their day each month; budgets are per category and month.
 */
final class Expenses
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly FileStore $files,
    ) {}

    /**
     * @param  string|null  $receipt  image or PDF bytes
     * @param  GateEntry|null  $vehicle  the vehicle the charge belongs to (freight, unloading, handling…)
     * @param  bool  $capitalize  part of that lot's landed cost: added to its stock value when posted
     */
    public function record(ExpenseCategory $category, CarbonImmutable $date, int $amountPaise, string $description, ?LedgerAccount $cash, ?Party $party,
        ?string $receipt = null, bool $post = false, ?GateEntry $vehicle = null, bool $capitalize = false): Expense
    {
        if ($capitalize && ($vehicle === null || $vehicle->direction !== 'inbound' || $category->kind !== 'expense')) {
            throw new BusinessRuleViolation('Only an expense for an inbound vehicle can be added to its stock cost.');
        }
        $this->require();
        $this->check($category, $date, $amountPaise, $description, $cash, $party);
        $file = filled($receipt) ? $this->files->put((string) $receipt) : null;
        if ($file !== null && ! in_array($file->mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new BusinessRuleViolation('Receipts must be photos (JPEG, PNG) or PDF.');
        }

        return DB::transaction(function () use ($category, $date, $amountPaise, $description, $cash, $party, $file, $post, $vehicle, $capitalize): Expense {
            $expense = Expense::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::EXPENSE, $date), 'kind' => $category->kind, 'expense_category_id' => $category->id,
                'entry_date' => $date->toDateString(), 'amount_paise' => $amountPaise, 'description' => trim($description),
                'cash_ledger_id' => $cash?->id, 'party_id' => $party?->id, 'receipt_file_id' => $file?->id,
                'gate_entry_id' => $vehicle?->id, 'capitalize_to_stock' => $capitalize,
            ]);
            $this->audit->record('expense.recorded', $expense, null, null, ['amount_paise' => $amountPaise, 'kind' => $category->kind]);

            return $post ? $this->post($expense) : $expense;
        });
    }

    /** Puts a draft in the books; the amount and the payment (cash-bank or party) can be corrected on the way. */
    public function post(Expense $expense, ?int $amountPaise = null, ?LedgerAccount $cash = null, ?Party $party = null): Expense
    {
        $who = $this->require();

        return DB::transaction(function () use ($expense, $amountPaise, $cash, $party, $who): Expense {
            $e = Expense::query()->with('category.ledger')->lockForUpdate()->findOrFail($expense->id);
            if ($e->status !== Expense::DRAFT) {
                throw new BusinessRuleViolation("{$e->number} is not a draft.");
            }
            if ($amountPaise !== null) {
                $e->forceFill(['amount_paise' => $amountPaise]);
            }
            // A corrected payment replaces the draft's: cash-bank or party, never both.
            if ($cash !== null || $party !== null) {
                $e->forceFill(['cash_ledger_id' => $cash?->id, 'party_id' => $cash === null ? $party?->id : null])->unsetRelation('cashLedger')->unsetRelation('party');
            }
            $category = $e->category;
            $this->check($category, CarbonImmutable::parse($e->entry_date->toDateString(), 'Asia/Karachi'), $e->amount_paise, $e->description, $e->cashLedger, $e->party);
            $other = $e->cash_ledger_id !== null ? $e->cashLedger : $this->chart->partyLedger($e->party);
            $memo = "{$e->number}: {$e->description}";
            $income = $category->kind === 'income';
            $lines = $income
                ? [['account' => $other, 'dr' => $e->amount_paise, 'memo' => $memo], ['account' => $category->ledger, 'cr' => $e->amount_paise, 'memo' => $memo]]
                : [['account' => $category->ledger, 'dr' => $e->amount_paise, 'memo' => $memo], ['account' => $other, 'cr' => $e->amount_paise, 'memo' => $memo]];
            $type = $e->cash_ledger_id === null ? 'journal' : ($income ? 'receipt' : 'payment');
            $voucher = $this->books->post($type, CarbonImmutable::parse($e->entry_date->toDateString(), 'Asia/Karachi'), $lines,
                "{$category->name}: {$e->description}", ['source_type' => 'expense', 'source_id' => $e->id]);
            $e->forceFill(['status' => Expense::POSTED, 'voucher_id' => $voucher->id, 'posted_by' => $who->id, 'posted_at' => now()])->save();
            $this->audit->record('expense.posted', $e, null, null, ['voucher' => $voucher->number, 'amount_paise' => $e->amount_paise]);
            if ($e->capitalize_to_stock && $e->gate_entry_id !== null) {
                app(StockValuation::class)->recordLandedCost($e);
            }

            return $e;
        });
    }

    public function cancel(Expense $expense, string $reason): Expense
    {
        $this->require();
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for cancelling.');
        }
        $e = Expense::query()->findOrFail($expense->id);
        if ($e->status !== Expense::DRAFT) {
            throw new BusinessRuleViolation('Only a draft can be cancelled; reverse the voucher of a posted entry.');
        }
        $e->forceFill(['status' => Expense::CANCELLED, 'cancel_reason' => trim($reason)])->save();
        $this->audit->record('expense.cancelled', $e, null, null, ['reason' => trim($reason)]);

        return $e;
    }

    public function setBudget(ExpenseCategory $category, string $month, int $amountPaise): ExpenseBudget
    {
        $this->require();
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new BusinessRuleViolation('Give the month as YYYY-MM.');
        }
        if ($amountPaise < 0) {
            throw new BusinessRuleViolation('A budget cannot be negative.');
        }
        $budget = ExpenseBudget::query()->where('expense_category_id', $category->id)->where('month', $month)->first()
            ?? new ExpenseBudget(['expense_category_id' => $category->id, 'month' => $month]);
        $before = $budget->exists ? $budget->amount_paise : null;
        $budget->amount_paise = $amountPaise;
        $budget->save();
        $this->audit->record('expense.budget_set', $budget, null, null, ['category' => $category->code, 'month' => $month, 'from' => $before, 'to' => $amountPaise]);

        return $budget;
    }

    /** @param  array<string, mixed>  $data */
    public function saveTemplate(?RecurringExpense $template, array $data): RecurringExpense
    {
        $this->require();
        $category = ExpenseCategory::query()->findOrFail($data['expense_category_id']);
        $cash = filled($data['pay_from_ledger_id'] ?? null) ? LedgerAccount::query()->findOrFail($data['pay_from_ledger_id']) : null;
        $party = filled($data['party_id'] ?? null) ? Party::query()->findOrFail($data['party_id']) : null;
        $day = (int) $data['day_of_month'];
        if ($day < 1 || $day > 28) {
            throw new BusinessRuleViolation('The day of the month is 1 to 28, so it exists every month.');
        }
        foreach (['starts_month', 'ends_month'] as $k) {
            if (filled($data[$k] ?? null) && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $data[$k])) {
                throw new BusinessRuleViolation('Give months as YYYY-MM.');
            }
        }
        if (filled($data['ends_month'] ?? null) && $data['ends_month'] < $data['starts_month']) {
            throw new BusinessRuleViolation('The last month is before the first.');
        }
        $this->check($category, null, (int) $data['amount_paise'], (string) $data['name'], $cash, $party);
        $template ??= new RecurringExpense;
        $template->fill([
            'name' => trim((string) $data['name']), 'expense_category_id' => $category->id, 'amount_paise' => (int) $data['amount_paise'], 'day_of_month' => $day,
            'pay_from_ledger_id' => $cash?->id, 'party_id' => $party?->id, 'starts_month' => (string) $data['starts_month'],
            'ends_month' => filled($data['ends_month'] ?? null) ? (string) $data['ends_month'] : null, 'active' => (bool) ($data['active'] ?? true),
        ])->save();

        return $template;
    }

    /**
     * Drafts this month's entry of every active template whose day has come (and catches up a month
     * missed while the server was down). Safe to run any number of times. Runs inside one entity.
     *
     * @return int drafts made
     */
    public function draftRecurring(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now('Asia/Karachi');
        $n = 0;
        foreach (RecurringExpense::query()->with('category')->where('active', true)->get() as $t) {
            foreach ([$today->subMonthNoOverflow(), $today] as $m) {
                $month = $m->format('Y-m');
                $due = $m->setDay($t->day_of_month)->startOfDay();
                if ($month < $t->starts_month || ($t->ends_month !== null && $month > $t->ends_month) || $due->greaterThan($today)
                    || Expense::query()->where('recurring_expense_id', $t->id)->where('recurring_month', $month)->exists()) {
                    continue;
                }
                $e = Expense::query()->create([
                    'number' => $this->numbers->next(DocumentNumbers::EXPENSE, $due), 'kind' => $t->category->kind, 'expense_category_id' => $t->expense_category_id,
                    'entry_date' => $due->toDateString(), 'amount_paise' => $t->amount_paise, 'description' => "{$t->name} ({$m->format('M Y')})",
                    'cash_ledger_id' => $t->pay_from_ledger_id, 'party_id' => $t->party_id, 'recurring_expense_id' => $t->id, 'recurring_month' => $month,
                ]);
                $this->audit->record('expense.drafted_recurring', $e, null, null, ['template' => $t->name, 'month' => $month]);
                $n++;
            }
        }

        return $n;
    }

    private function check(ExpenseCategory $category, ?CarbonImmutable $date, int $amountPaise, string $description, ?LedgerAccount $cash, ?Party $party): void
    {
        if ($category->status !== 'active') {
            throw new BusinessRuleViolation("Category {$category->name} is not in use.");
        }
        if ($amountPaise <= 0) {
            throw new BusinessRuleViolation('The amount must be more than zero.');
        }
        if (mb_strlen(trim($description)) < 3) {
            throw new BusinessRuleViolation('Describe the entry.');
        }
        if ($date !== null && $date->greaterThan(CarbonImmutable::now('Asia/Karachi')->endOfDay())) {
            throw new BusinessRuleViolation('The date cannot be in the future.');
        }
        if (($cash === null) === ($party === null)) {
            throw new BusinessRuleViolation('Choose either the cash or bank account, or the party it is owed to / by.');
        }
        if ($cash !== null && ! in_array($cash->kind, ['cash', 'bank'], true)) {
            throw new BusinessRuleViolation('Pay from (or receive into) a cash or bank account.');
        }
        ExpenseCategories::assertLedgerFits($category->kind, $category->ledger);
    }

    private function require(): LoginAccount
    {
        $account = $this->actor->account();
        if (! ($account?->hasPermission('ledger.post') ?? false)) {
            throw new BusinessRuleViolation('Entering expenses needs the permission ledger.post.');
        }

        return $account;
    }
}
