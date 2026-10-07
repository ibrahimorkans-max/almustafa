<?php

declare(strict_types=1);

namespace App\Models\Production;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hourly, product-wise production record (append-only). A mistake is corrected by a reversing
 * log (reverses_id) with a reason, never by editing.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $batch_id
 * @property string $kind
 * @property string $product_id
 * @property string $godown_id
 * @property string $quantity_kg
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string|null $reverses_id
 * @property string|null $requisition_id
 * @property string|null $stock_ledger_id
 * @property string|null $note
 * @property string|null $device_id
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Product $product
 * @property-read Godown $godown
 */
class ProductionLog extends Model
{
    use AppendOnly;
    use Auditable;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const INPUT = 'input';

    public const OUTPUT = 'output';

    protected $fillable = [
        'entity_id', 'batch_id', 'kind', 'product_id', 'godown_id', 'quantity_kg', 'period_start', 'period_end',
        'reverses_id', 'requisition_id', 'stock_ledger_id', 'note', 'device_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['quantity_kg' => 'decimal:3', 'period_start' => 'datetime', 'period_end' => 'datetime', 'created_at' => 'datetime'];
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

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }
}
