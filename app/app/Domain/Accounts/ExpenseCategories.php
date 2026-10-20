<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use Illuminate\Support\Facades\DB;

/**
 * Expense and income categories, each tied to one ledger. The standard ones (and their ledgers) are
 * installed the first time categories are used; accounts add more. A category's ledger must be an
 * expense ledger for an expense and an income ledger for income, so the profit and loss stays right.
 */
final class ExpenseCategories
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly ChartOfAccounts $chart,
    ) {}

    /** Installs the default categories a mill does not have yet (a release can add one, e.g. freight). */
    public function installDefaults(): void
    {
        $have = ExpenseCategory::query()->pluck('code')->all();
        if (array_diff(array_keys(ExpenseCategory::DEFAULTS), $have) === []) {
            return;
        }
        DB::transaction(function () use ($have): void {
            foreach (ExpenseCategory::DEFAULTS as $code => [$name, $kind, $ledgerCode, $ledgerName, $group, $claimable]) {
                if (in_array($code, $have, true)) {
                    continue;
                }
                $ledger = LedgerAccount::query()->where('code', $ledgerCode)->first()
                    ?? LedgerAccount::query()->create(['group_id' => $this->chart->group($group)->id, 'code' => $ledgerCode, 'name' => $ledgerName, 'kind' => 'general']);
                ExpenseCategory::query()->create(['code' => $code, 'name' => $name, 'kind' => $kind, 'ledger_account_id' => $ledger->id, 'claimable' => $claimable]);
            }
        });
    }

    public function create(string $code, string $name, string $kind, LedgerAccount $ledger, bool $claimable): ExpenseCategory
    {
        if (! ($this->actor->account()?->hasPermission('ledger.post') ?? false)) {
            throw new BusinessRuleViolation('Managing categories needs the permission ledger.post.');
        }
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $code)) {
            throw new BusinessRuleViolation('The code is 2–20 capital letters, digits or _.');
        }
        if (ExpenseCategory::query()->where('code', $code)->exists()) {
            throw new BusinessRuleViolation("Category {$code} exists.");
        }
        self::assertLedgerFits($kind, $ledger);

        return ExpenseCategory::query()->create(['code' => $code, 'name' => trim($name), 'kind' => $kind, 'ledger_account_id' => $ledger->id, 'claimable' => $claimable && $kind === 'expense']);
    }

    public static function assertLedgerFits(string $kind, LedgerAccount $ledger): void
    {
        $nature = $ledger->group->nature ?? null;
        if (! in_array($kind, ['expense', 'income'], true) || $nature !== $kind) {
            throw new BusinessRuleViolation("Choose an {$kind} ledger for an {$kind} category.");
        }
    }
}
