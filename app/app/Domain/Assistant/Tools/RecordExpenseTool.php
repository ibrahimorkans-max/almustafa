<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Accounts\Expenses;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Filament\Support\PrintButton;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;

/** Records and posts an expense (or other income) paid in cash or from a bank account. */
final class RecordExpenseTool extends Tool
{
    public function name(): string
    {
        return 'record_expense';
    }

    public function description(): string
    {
        return 'Record and post an expense or other income: category (part of its name, e.g. "diesel", "electricity", "tea"), amount in rupees, what it was for, and paid from cash (default) or the bank.';
    }

    public function properties(): array
    {
        return [
            'category' => ['type' => 'string', 'description' => 'Part of the expense category name.'],
            'amount' => ['type' => 'number', 'description' => 'Amount in rupees.'],
            'description' => ['type' => 'string'],
            'paid_from' => ['type' => 'string', 'enum' => ['cash', 'bank'], 'description' => 'Default cash.'],
        ];
    }

    public function required(): array
    {
        return ['category', 'amount', 'description'];
    }

    public function permission(): string
    {
        return 'ledger.post';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/\b(?:expense|kharcha|spent|paid)\s+(?:rs\.?\s*)?(?<amount>[\d,]+(?:\.\d{1,2})?)\s+(?:for|on)\s+(?<category>[\w ]{2,30}?)(?:\s*[:\-]\s*(?<description>.+?))?(?:\s+from\s+(?<paid_from>cash|bank))?$/i'];
    }

    public function examples(): array
    {
        return ['expense 4500 for diesel: generator', 'expense 1200 for tea from cash'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        $category = $this->category((string) ($input['category'] ?? ''));

        return 'Post '.($category?->kind === 'income' ? 'income' : 'expense').' '.Money::rupees((string) str_replace(',', '', (string) ($input['amount'] ?? '0')))->format()
            .' under "'.($category === null ? $input['category'] : $category->name).'" — '.($input['description'] ?? $input['category']).', '.(($input['paid_from'] ?? 'cash') === 'bank' ? 'from the bank' : 'in cash').'.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $category = $this->category((string) $input['category']);
        if ($category === null) {
            return ToolResult::fail('No expense category matches "'.$input['category'].'". Open Accounts → Expense categories to see them.');
        }
        $cash = ($input['paid_from'] ?? 'cash') === 'bank'
            ? LedgerAccount::query()->where('kind', 'bank')->orderBy('code')->first()
            : app(ChartOfAccounts::class)->system('cash');
        if ($cash === null) {
            return ToolResult::fail('No bank account is set up; say "from cash" or add the bank under Ledger accounts.');
        }
        $amount = Money::rupees((string) str_replace(',', '', (string) $input['amount']))->paise;
        $expense = app(Expenses::class)->record($category, CarbonImmutable::now('Asia/Karachi')->startOfDay(), $amount,
            (string) ($input['description'] ?? $category->name), $cash, null, post: true);

        return new ToolResult("Posted {$expense->number}: ".Money::paise($amount)->format()." under {$category->name}.",
            [['label' => 'Print '.($category->kind === 'income' ? 'receipt' : 'expense voucher'), 'url' => PrintButton::url('expense', $expense)]]);
    }

    private function category(string $words): ?ExpenseCategory
    {
        $words = trim($words);
        app(ExpenseCategories::class)->installDefaults();

        return $words === '' ? null : ExpenseCategory::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($words).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($words).'%']))->orderBy('name')->first();
    }
}
