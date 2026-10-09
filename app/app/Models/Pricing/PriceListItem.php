<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Product;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of a product's rate in a price list (append-only). The newest row for a product is
 * the current rate; older rows are its history.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $price_list_id
 * @property string $product_id
 * @property int $rate_paise_per_maund
 * @property string|null $supersedes_id
 * @property string|null $approval_request_id
 * @property string|null $reason
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Product $product
 */
class PriceListItem extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['entity_id', 'price_list_id', 'product_id', 'rate_paise_per_maund', 'supersedes_id', 'approval_request_id', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['rate_paise_per_maund' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
