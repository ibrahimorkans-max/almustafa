<?php

declare(strict_types=1);

namespace App\Domain\Masters;

use App\Filament\Resources\Accounts\ExpenseResource;
use App\Filament\Resources\Accounts\VoucherResource;
use App\Filament\Resources\Claims\ClaimResource;
use App\Filament\Resources\Gate\DeductionRuleResource;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Inventory\PaddyStockEntryResource;
use App\Filament\Resources\Masters\GodownResource;
use App\Filament\Resources\Masters\PaddyVarietyResource;
use App\Filament\Resources\Masters\PartyResource;
use App\Filament\Resources\Masters\ProductResource;
use App\Filament\Resources\Masters\VehicleResource;
use App\Filament\Resources\Procurement\SaudaResource;
use App\Filament\Resources\Production\ProductionBatchResource;
use App\Filament\Resources\Sales\SalesSaudaResource;
use App\Models\Accounts\Expense;
use App\Models\Accounts\Voucher;
use App\Models\Claims\Claim;
use App\Models\CodeDirectoryEntry;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\PaddyStockEntry;
use App\Models\Masters\Godown;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Models\Production\ProductionBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The Code Directory: every code the system made, in one list (Masters → Code directory).
 * One read-only UNION over the records themselves, each part with its own model's mill scope (and
 * the database's row security), so a mill only ever sees its own codes. Nothing is stored twice and
 * nothing here can change a code.
 */
final class CodeDirectory
{
    /** module => label */
    public const MODULES = [
        'party' => 'Party', 'product' => 'Product', 'paddy_variety' => 'Paddy variety', 'vehicle' => 'Vehicle', 'godown' => 'Godown',
        'deduction_rule' => 'Deduction rule', 'purchase_sauda' => 'Purchase sauda', 'sales_sauda' => 'Sales sauda', 'gate_token' => 'Gate token',
        'stock_entry' => 'Paddy stock entry', 'production_batch' => 'Production batch', 'claim' => 'Claim', 'expense' => 'Expense', 'voucher' => 'Voucher',
    ];

    /** @return Builder<CodeDirectoryEntry> */
    public static function query(): Builder
    {
        $partyName = fn (string $table) => "(SELECT name FROM parties WHERE parties.id = {$table}.party_id)";
        $token = DB::getDriverName() === 'pgsql' ? "LPAD(CAST(token_serial AS TEXT), 6, '0')" : "LPAD(token_serial, 6, '0')";

        /** @var list<QueryBuilder> $parts */
        $parts = [
            self::part(Party::query(), 'party', 'code', 'name'),
            self::part(Product::query(), 'product', 'code', 'name'),
            self::part(PaddyVariety::query(), 'paddy_variety', 'code', 'name'),
            self::part(Vehicle::query()->whereNotNull('code'), 'vehicle', 'code', 'registration_no'),
            self::part(Godown::query(), 'godown', 'code', 'name'),
            self::part(DeductionRule::query()->whereNotNull('code'), 'deduction_rule', 'code', 'name'),
            self::part(Sauda::query()->where('direction', 'purchase'), 'purchase_sauda', 'number', $partyName('saudas')),
            self::part(Sauda::query()->where('direction', 'sale'), 'sales_sauda', 'number', $partyName('saudas')),
            self::part(GateEntry::query()->whereNotNull('token_serial'), 'gate_token', $token, "COALESCE({$partyName('gate_entries')}, party_name, registration_no)"),
            self::part(PaddyStockEntry::query(), 'stock_entry', 'number', "COALESCE({$partyName('paddy_stock_entries')}, party_name)"),
            self::part(ProductionBatch::query(), 'production_batch', 'number', '(SELECT name FROM products WHERE products.id = production_batches.paddy_product_id)'),
            self::part(Claim::query(), 'claim', 'number', $partyName('claims')),
            self::part(Expense::query(), 'expense', 'number', 'description'),
            self::part(Voucher::query(), 'voucher', 'number', 'narration'),
        ];
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return CodeDirectoryEntry::query()->fromSub($union, 'code_directory');
    }

    /** Where a row opens: the record's own page, or its list searched for the code. */
    public static function url(CodeDirectoryEntry $e): ?string
    {
        $list = fn (string $resource) => $resource::canViewAny() ? $resource::getUrl('index', ['tableSearch' => $e->code]) : null;
        $view = fn (string $resource) => $resource::canViewAny() ? $resource::getUrl('view', ['record' => $e->record_id]) : null;

        return match ($e->module) {
            'party' => $list(PartyResource::class),
            'product' => $list(ProductResource::class),
            'paddy_variety' => $list(PaddyVarietyResource::class),
            'vehicle' => $list(VehicleResource::class),
            'godown' => $list(GodownResource::class),
            'deduction_rule' => $list(DeductionRuleResource::class),
            'purchase_sauda' => $view(SaudaResource::class),
            'sales_sauda' => $view(SalesSaudaResource::class),
            'gate_token' => $view(GateEntryResource::class),
            'stock_entry' => $view(PaddyStockEntryResource::class),
            'production_batch' => $view(ProductionBatchResource::class),
            'claim' => $view(ClaimResource::class),
            'expense' => $list(ExpenseResource::class),
            'voucher' => $view(VoucherResource::class),
            default => null,
        };
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private static function part(Builder $query, string $module, string $code, string $name): QueryBuilder
    {
        $table = $query->getModel()->getTable();

        return $query->toBase()->select([])->selectRaw(
            "'{$module}' AS module, {$code} AS code, {$name} AS name, {$table}.id AS record_id, {$table}.created_at AS created_at"
        );
    }
}
