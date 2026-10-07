<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Live balance per product × godown. A projection of the ledger, written only by StockLedger
 * (under a row lock) and verifiable with `php artisan stock:verify`.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $product_id
 * @property string $godown_id
 * @property string $quantity_kg
 * @property string|null $last_entry_id
 * @property-read Product $product
 * @property-read Godown $godown
 */
class StockBalance extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const CREATED_AT = null;

    protected $fillable = ['entity_id', 'product_id', 'godown_id', 'quantity_kg', 'last_entry_id'];

    protected function casts(): array
    {
        return ['quantity_kg' => 'decimal:3'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Godown, $this> */
    public function godown(): BelongsTo
    {
        return $this->belongsTo(Godown::class);
    }
}
