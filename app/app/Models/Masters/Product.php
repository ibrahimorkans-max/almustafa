<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * Stock is always kept in kilograms; sale_unit only controls how quantities are entered/shown.
 *
 * @property string $code
 * @property string $name
 * @property string $category
 * @property string|null $variety
 * @property string|null $hsn_code
 * @property string|null $tax_rate_id
 * @property string|null $brand_id
 * @property string $sale_unit
 * @property string|null $bag_weight_kg
 * @property bool $is_stock_item
 * @property string $status
 * @property-read TaxRate|null $taxRate
 * @property-read Brand|null $brand
 */
class Product extends MasterModel
{
    public const SYNC_TYPE = 'product';

    public const CATEGORIES = ['paddy', 'rice', 'by_product', 'packing', 'consumable', 'other'];

    public const SALE_UNITS = ['kg', 'quintal', 'ton', 'bag'];

    protected $fillable = [
        'code', 'name', 'category', 'variety', 'hsn_code', 'tax_rate_id', 'brand_id',
        'sale_unit', 'bag_weight_kg', 'is_stock_item', 'status',
    ];

    protected function casts(): array
    {
        return [
            'bag_weight_kg' => 'decimal:3',
            'is_stock_item' => 'boolean',
            'version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public static function writeRules(?MasterModel $existing): array
    {
        $entity = app(TenantContext::class)->entityId();

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'variety' => ['nullable', 'string', 'max:60'],
            'hsn_code' => ['nullable', 'regex:/^\d{4}(\d{2}){0,2}$/'],
            'tax_rate_id' => ['nullable', 'uuid', Rule::exists('tax_rates', 'id')->where('entity_id', $entity)],
            'brand_id' => ['nullable', 'uuid', Rule::exists('brands', 'id')->where('entity_id', $entity)],
            'sale_unit' => ['required', Rule::in(self::SALE_UNITS)],
            'bag_weight_kg' => ['nullable', 'required_if:sale_unit,bag', 'regex:/^\d{1,6}(\.\d{1,3})?$/'],
            'is_stock_item' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function writableFields(): array
    {
        return [
            'code', 'name', 'category', 'variety', 'hsn_code', 'tax_rate_id', 'brand_id',
            'sale_unit', 'bag_weight_kg', 'is_stock_item', 'status',
        ];
    }

    public function toSyncArray(LoginAccount $viewer): array
    {
        return $this->syncEnvelope() + [
            'code' => $this->code, 'name' => $this->name, 'category' => $this->category, 'variety' => $this->variety,
            'hsn_code' => $this->hsn_code, 'tax_rate_id' => $this->tax_rate_id, 'brand_id' => $this->brand_id,
            'sale_unit' => $this->sale_unit, 'bag_weight_kg' => $this->bag_weight_kg,
            'is_stock_item' => $this->is_stock_item, 'status' => $this->status,
        ];
    }
}
