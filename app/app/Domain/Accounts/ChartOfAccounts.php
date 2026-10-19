<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\LedgerAccount;
use App\Models\Masters\Party;
use Illuminate\Support\Facades\DB;

/**
 * Standard chart of accounts per entity (Tally-style groups familiar to Indian accountants) and
 * the system ledgers the application posts to. Installing is idempotent.
 */
final class ChartOfAccounts
{
    /** code => [name, parent code, nature] */
    public const GROUPS = [
        'CAPITAL' => ['Capital Account', null, 'liability'],
        'LOANS' => ['Loans (Liability)', null, 'liability'],
        'CUR_LIAB' => ['Current Liabilities', null, 'liability'],
        'SUNDRY_CREDITORS' => ['Sundry Creditors', 'CUR_LIAB', 'liability'],
        'DUTIES_TAXES' => ['Duties & Taxes', 'CUR_LIAB', 'liability'],
        'PROVISIONS' => ['Provisions', 'CUR_LIAB', 'liability'],
        'FIXED_ASSETS' => ['Fixed Assets', null, 'asset'],
        'CUR_ASSETS' => ['Current Assets', null, 'asset'],
        'SUNDRY_DEBTORS' => ['Sundry Debtors', 'CUR_ASSETS', 'asset'],
        'BANK_ACCOUNTS' => ['Bank Accounts', 'CUR_ASSETS', 'asset'],
        'CASH_IN_HAND' => ['Cash-in-hand', 'CUR_ASSETS', 'asset'],
        'STOCK_IN_HAND' => ['Stock-in-hand', 'CUR_ASSETS', 'asset'],
        'LOANS_ADVANCES' => ['Loans & Advances (Asset)', 'CUR_ASSETS', 'asset'],
        'PURCHASE_ACCOUNTS' => ['Purchase Accounts', null, 'expense'],
        'DIRECT_EXPENSES' => ['Direct Expenses', null, 'expense'],
        'INDIRECT_EXPENSES' => ['Indirect Expenses', null, 'expense'],
        'SALES_ACCOUNTS' => ['Sales Accounts', null, 'income'],
        'DIRECT_INCOMES' => ['Direct Incomes', null, 'income'],
        'INDIRECT_INCOMES' => ['Indirect Incomes', null, 'income'],
    ];

    /** system_key => [code, name, group code, kind] */
    public const SYSTEM_LEDGERS = [
        'purchase_paddy' => ['PUR-PADDY', 'Purchase – Paddy', 'PURCHASE_ACCOUNTS', 'system'],
        'rate_cut' => ['PUR-RATECUT', 'Quality rate cut on purchase', 'PURCHASE_ACCOUNTS', 'system'],
        'brokerage' => ['EXP-BROKERAGE', 'Brokerage on purchase', 'DIRECT_EXPENSES', 'system'],
        'round_off' => ['EXP-ROUNDOFF', 'Round off', 'INDIRECT_EXPENSES', 'system'],
        'cash' => ['CASH', 'Cash', 'CASH_IN_HAND', 'cash'],
        'capital' => ['CAPITAL', 'Proprietor / partners capital', 'CAPITAL', 'system'],
        'sales_rice' => ['SAL-RICE', 'Sales – Rice', 'SALES_ACCOUNTS', 'system'],
        'sales_byproduct' => ['SAL-BYPRODUCT', 'Sales – By-products', 'SALES_ACCOUNTS', 'system'],
        'output_sales_tax' => ['ST-OUTPUT', 'Output sales tax', 'DUTIES_TAXES', 'system'],
        'further_tax' => ['ST-FURTHER', 'Further sales tax (s. 3(1A))', 'DUTIES_TAXES', 'system'],
        'sales_claims' => ['SAL-CLAIMS', 'Sales claims & allowances', 'SALES_ACCOUNTS', 'system'],
        'purchase_claims' => ['PUR-CLAIMS', 'Purchase claims recovered', 'PURCHASE_ACCOUNTS', 'system'],
        'wht_payable' => ['WHT-153', 'Income tax withheld payable (s. 153)', 'DUTIES_TAXES', 'system'],
        // Payroll (HR phases 5-7): operating expenses, what is owed to staff and to the authorities.
        'staff_advances' => ['STAFF-ADV', 'Advances and loans to staff', 'LOANS_ADVANCES', 'system'],
        'salaries_expense' => ['EXP-SALARY', 'Salaries and wages', 'INDIRECT_EXPENSES', 'system'],
        'overtime_expense' => ['EXP-OVERTIME', 'Overtime', 'INDIRECT_EXPENSES', 'system'],
        'bonus_expense' => ['EXP-BONUS', 'Bonus and festival bonus', 'INDIRECT_EXPENSES', 'system'],
        'eobi_expense' => ['EXP-EOBI', 'EOBI (employer share)', 'INDIRECT_EXPENSES', 'system'],
        'pessi_expense' => ['EXP-PESSI', 'Social security PESSI (employer)', 'INDIRECT_EXPENSES', 'system'],
        'salaries_payable' => ['SAL-PAYABLE', 'Salaries and wages payable', 'CUR_LIAB', 'system'],
        'salary_tax_payable' => ['WHT-149', 'Income tax on salary payable (s. 149)', 'DUTIES_TAXES', 'system'],
        'eobi_payable' => ['EOBI-PAYABLE', 'EOBI payable', 'DUTIES_TAXES', 'system'],
        'pessi_payable' => ['PESSI-PAYABLE', 'PESSI payable', 'DUTIES_TAXES', 'system'],
        'staff_other_deductions' => ['STAFF-DED', 'Other deductions from staff (fines, canteen)', 'INDIRECT_INCOMES', 'system'],
        'staff_claims_payable' => ['STAFF-CLAIMS', 'Staff expense claims payable', 'CUR_LIAB', 'system'],
        'contract_labour' => ['EXP-CONTRACT-LAB', 'Contract labour (incl. sales tax on services)', 'DIRECT_EXPENSES', 'system'],
        // Daily wages of unregistered casual labour (labour groups under a mukadam).
        'daily_wages' => ['EXP-DAILY-WAGES', 'Daily wages (casual labour)', 'DIRECT_EXPENSES', 'system'],
        'daily_wages_payable' => ['DW-PAYABLE', 'Daily wages payable', 'CUR_LIAB', 'system'],
    ];

    public function __construct(private readonly TenantContext $tenant) {}

    public function install(string $entityId): void
    {
        $this->tenant->runInEntity($entityId, function () use ($entityId): void {
            DB::transaction(function () use ($entityId): void {
                $ids = AccountGroup::query()->pluck('id', 'code')->all();
                $sort = 0;
                foreach (self::GROUPS as $code => [$name, $parent, $nature]) {
                    $ids[$code] ??= AccountGroup::query()->create([
                        'entity_id' => $entityId, 'code' => $code, 'name' => $name, 'parent_id' => $parent ? $ids[$parent] : null,
                        'nature' => $nature, 'is_system' => true, 'sort' => ++$sort,
                    ])->id;
                }
                foreach (self::SYSTEM_LEDGERS as $key => [$code, $name, $group, $kind]) {
                    if (! LedgerAccount::query()->where('system_key', $key)->exists()) {
                        LedgerAccount::query()->create([
                            'entity_id' => $entityId, 'group_id' => $ids[$group], 'code' => $code, 'name' => $name,
                            'kind' => $kind, 'system_key' => $key,
                        ]);
                    }
                }
            });
        });
    }

    public function system(string $key): LedgerAccount
    {
        $account = LedgerAccount::query()->where('system_key', $key)->first();
        if ($account === null) {
            $this->install($this->tenant->requireEntityId());
            $account = LedgerAccount::query()->where('system_key', $key)->first();
        }

        return $account ?? throw new BusinessRuleViolation("System ledger {$key} is missing.");
    }

    public function group(string $code): AccountGroup
    {
        $group = AccountGroup::query()->where('code', $code)->first();
        if ($group === null) {
            $this->install($this->tenant->requireEntityId());
            $group = AccountGroup::query()->where('code', $code)->firstOrFail();
        }

        return $group;
    }

    /** The party's own ledger, created on first use (creditor for suppliers / brokers, debtor otherwise). */
    public function partyLedger(Party $party): LedgerAccount
    {
        $existing = LedgerAccount::query()->where('party_id', $party->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $creditor = $party->is_vendor || $party->is_farmer || $party->is_broker || $party->is_transporter;

        return LedgerAccount::query()->create([
            'entity_id' => $party->entity_id,
            'group_id' => $this->group($creditor ? 'SUNDRY_CREDITORS' : 'SUNDRY_DEBTORS')->id,
            'code' => 'P-'.$party->code,
            'name' => $party->name,
            'kind' => 'party',
            'party_id' => $party->id,
        ]);
    }
}
