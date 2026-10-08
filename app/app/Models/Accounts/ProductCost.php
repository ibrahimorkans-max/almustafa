<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Product;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * History of a product's weighted-average cost (append-only; the latest row is current).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $product_id
 * @property string $avg_paise_per_kg
 * @property string $basis_kg
 * @property string $source_type
 * @property string|null $source_id
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 * @property-read Product $product
 */
class ProductCost extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['entity_id', 'product_id', 'avg_paise_per_kg', 'basis_kg', 'source_type', 'source_id', 'data', 'created_at'];

    protected function casts(): array
    {
        return ['avg_paise_per_kg' => 'decimal:4', 'basis_kg' => 'decimal:3', 'data' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
