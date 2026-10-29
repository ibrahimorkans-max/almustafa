<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\BankStatementLine;
use App\Models\Accounts\Expense;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PurchaseBill;
use App\Models\Accounts\RoznamchaPage;
use App\Models\Accounts\Voucher;
use App\Models\Banking\BankEmailTransaction;
use App\Models\Claims\Claim;
use App\Models\Customer;
use App\Models\DataVisibilityRule;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\PaddyStockEntry;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\StockCount;
use App\Models\Procurement\PaddyRequirement;
use App\Models\Procurement\Quote;
use App\Models\Procurement\Sauda;
use App\Models\Production\ProductionBatch;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data visibility windows (Platform → Data visibility): how far back a customer sees its own records.
 *
 * Which rule: the customer's own rule for the module, else its own "all modules" rule, else its plan's
 * rule for the module, else the plan's "all modules" rule. An inactive rule, one not started yet, or one
 * with a manual override running means no limit. After the access end date plus the grace days, nothing
 * of the module is shown until the product owner extends or restores it.
 *
 * Where it applies: the main records of each module (MODELS), in the customer's screens, lists, search,
 * the Code Directory, prints of lists, report periods and the API, for signed-in customer users only
 * (ApplyDataVisibility switches it on per request). Scheduled jobs, the product owner and the books'
 * own arithmetic (ledger lines, stock balances) are not limited, so balances stay right.
 * Nothing is ever deleted: hidden records come back when the window is extended.
 */
final class DataVisibility
{
    public const MODULES = [
        'all' => 'All modules', 'procurement' => 'Procurement (saudas, quotes, purchase bills)', 'gate' => 'Gate & weighbridge',
        'inventory' => 'Stock & production', 'sales' => 'Sales & dispatch', 'accounts' => 'Accounts (vouchers, payments, expenses, bank)',
        'claims' => 'Claims',
    ];

    /** module => [model class => business date column] */
    public const MODELS = [
        'procurement' => [
            Sauda::class => 'created_at', Quote::class => 'created_at',
            PaddyRequirement::class => 'created_at', PurchaseBill::class => 'bill_date',
        ],
        'gate' => [GateEntry::class => 'arrived_at'],
        'inventory' => [
            PaddyStockEntry::class => 'entry_date', StockCount::class => 'count_date',
            Requisition::class => 'created_at', ProductionBatch::class => 'opened_at',
        ],
        'sales' => [SalesBill::class => 'bill_date', DispatchOrder::class => 'created_at'],
        'accounts' => [
            Voucher::class => 'voucher_date', Payment::class => 'payment_date',
            Expense::class => 'entry_date', BankEmailTransaction::class => 'created_at',
            RoznamchaPage::class => 'entry_date', BankStatementLine::class => 'txn_date',
        ],
        'claims' => [Claim::class => 'created_at'],
    ];

    /** Nothing of the module is visible (its access ended). */
    public const NONE = 'none';

    /** The customer whose screens are limited in this request (set by ApplyDataVisibility). */
    private static ?string $customerId = null;

    /** @var array<string, array<string, CarbonImmutable|string|null>> per-request cache: customer => module => limit */
    private static array $limits = [];

    public static function applyFor(?string $customerId): void
    {
        self::$customerId = $customerId;
    }

    public static function stop(): void
    {
        self::$customerId = null;
    }

    public static function forget(): void
    {
        self::$limits = [];
    }

    /** Registers the filter on every module's records (AppServiceProvider). */
    public static function register(): void
    {
        foreach (self::MODELS as $module => $models) {
            foreach ($models as $class => $column) {
                $class::addGlobalScope('data_visibility', function (Builder $query) use ($module, $column): void {
                    $limit = self::limitNow($module);
                    if ($limit === null) {
                        return;
                    }
                    // Date columns (bill_date …) compare as Pakistan dates; time stamps (created_at …) as the moment.
                    $bound = $limit === self::NONE ? null : (str_ends_with($column, '_date') ? $limit->toDateString() : $limit->utc());
                    $bound === null ? $query->whereRaw('1 = 0') : $query->where($query->getModel()->qualifyColumn($column), '>=', $bound);
                });
            }
        }
    }

    /** The limit for this request's customer, if the filter is on (null: everything visible). */
    public static function limitNow(string $module): CarbonImmutable|string|null
    {
        if (self::$customerId === null || app(TenantContext::class)->bypassesIsolation()) {
            return null;
        }

        return self::limit(self::$customerId, $module);
    }

    /**
     * The earliest moment a customer may see in a module: a date (Pakistan midnight), NONE, or null for no limit.
     */
    public static function limit(string $customerId, string $module, ?CarbonImmutable $today = null): CarbonImmutable|string|null
    {
        if ($today === null && array_key_exists($module, self::$limits[$customerId] ?? [])) {
            return self::$limits[$customerId][$module];
        }
        $rule = self::rule($customerId, $module);
        $value = $rule === null ? null : self::evaluate($rule, $today ?? CarbonImmutable::now('Asia/Karachi'));
        if ($today === null) {
            self::$limits[$customerId][$module] = $value;
        }

        return $value;
    }

    public static function evaluate(DataVisibilityRule $rule, CarbonImmutable $today): CarbonImmutable|string|null
    {
        $day = $today->timezone('Asia/Karachi')->startOfDay();
        if ($rule->status !== 'active'
            || ($rule->override_until !== null && $day->lte(CarbonImmutable::parse($rule->override_until->toDateString(), 'Asia/Karachi')))
            || ($rule->starts_on !== null && $day->lt(CarbonImmutable::parse($rule->starts_on->toDateString(), 'Asia/Karachi')))) {
            return null;
        }
        if ($rule->expires_on !== null && $day->gt(CarbonImmutable::parse($rule->expires_on->toDateString(), 'Asia/Karachi')->addDays($rule->grace_days))) {
            return self::NONE;
        }
        if ($rule->duration === null || $rule->duration <= 0) {
            return null;
        }

        return $rule->duration_unit === 'months' ? $day->subMonthsNoOverflow($rule->duration) : $day->subDays($rule->duration);
    }

    /** The rule that decides for a customer and module (see the class comment), or null. */
    public static function rule(string $customerId, string $module): ?DataVisibilityRule
    {
        return app(TenantContext::class)->runAsSystem(function () use ($customerId, $module): ?DataVisibilityRule {
            $planId = Customer::query()->whereKey($customerId)->value('plan_id');
            $rules = DataVisibilityRule::query()
                ->where(fn ($q) => $q->where('customer_id', $customerId)->when($planId !== null, fn ($q) => $q->orWhere(fn ($q) => $q->whereNull('customer_id')->where('plan_id', $planId))))
                ->whereIn('module', [$module, 'all'])
                ->get();

            return $rules->sortBy(fn (DataVisibilityRule $r) => ($r->customer_id !== null ? 0 : 2) + ($r->module === $module ? 0 : 1))->first();
        });
    }

    /** The customer of the mill (or company) being worked in. */
    public static function customerOf(TenantContext $tenant): ?string
    {
        if ($tenant->customerId() !== null) {
            return $tenant->customerId();
        }
        $entityId = $tenant->entityId();

        return $entityId === null ? null : $tenant->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('customer_id'));
    }

    /** A report period's start as a date, moved forward to the first day the customer may see. */
    public static function clampDate(CarbonInterface $from, string $module = 'all'): CarbonImmutable
    {
        return CarbonImmutable::parse(self::clampFrom($module, $from->toDateString()), 'Asia/Karachi');
    }

    /** A report period's start, moved forward to the first day the customer may see. */
    public static function clampFrom(string $module, string $from): string
    {
        $limit = self::limitNow($module) ?? self::limitNow('all');

        return match (true) {
            $limit === null => $from,
            $limit === self::NONE => CarbonImmutable::now('Asia/Karachi')->addDay()->toDateString(),
            default => max($from, $limit->toDateString()),
        };
    }

    /**
     * What the customer sees about its limits, for the banner: one line per module that is limited.
     *
     * @return list<string>
     */
    public static function notices(): array
    {
        if (self::$customerId === null) {
            return [];
        }
        $lines = [];
        foreach (array_keys(self::MODULES) as $module) {
            $limit = self::limitNow($module);
            if ($limit === null || ($module !== 'all' && $limit == self::limitNow('all'))) {
                continue;
            }
            $label = $module === 'all' ? 'Your records' : self::MODULES[$module];
            $lines[] = $limit === self::NONE
                ? "{$label}: access has ended. The records are kept; ask for access to be restored."
                : "{$label}: records before ".$limit->format('d M Y').' are not shown on your plan. They are kept, and come back when the period is extended.';
        }

        return $lines;
    }
}
