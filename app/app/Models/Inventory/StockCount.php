<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Physical count against the system balance at counting time. Its difference becomes an
 * adjustment only after maker-checker approval.
 *
 * @property string $id
 * @property string $entity_id
 * @property Carbon $count_date
 * @property string $product_id
 * @property string $godown_id
 * @property string $system_kg
 * @property string $counted_kg
 * @property string $difference_kg
 * @property string $status
 * @property string|null $approval_request_id
 * @property string|null $stock_ledger_id
 * @property string|null $note
 * @property-read Product $product
 * @property-read Godown $godown
 */
class StockCount extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['count_date', 'product_id', 'godown_id', 'system_kg', 'counted_kg', 'difference_kg', 'note'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'count_date' => 'date', 'system_kg' => 'decimal:3', 'counted_kg' => 'decimal:3', 'difference_kg' => 'decimal:3', 'version' => 'integer',
        ];
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
