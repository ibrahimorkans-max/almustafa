<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\ProductionBatch;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Request to move stock to a production batch or another godown.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $product_id
 * @property string $from_godown_id
 * @property string|null $to_godown_id
 * @property string|null $to_batch_id
 * @property string $quantity_kg
 * @property string $status
 * @property string|null $purpose
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $issued_at
 * @property string|null $decision_note
 * @property string|null $created_by
 * @property int $version
 * @property-read Product $product
 * @property-read Godown $fromGodown
 * @property-read Godown|null $toGodown
 * @property-read ProductionBatch|null $toBatch
 */
class Requisition extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const ISSUED = 'issued';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['number', 'product_id', 'from_godown_id', 'to_godown_id', 'to_batch_id', 'quantity_kg', 'purpose'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::REQUESTED];

    protected function casts(): array
    {
        return ['quantity_kg' => 'decimal:3', 'approved_at' => 'datetime', 'issued_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Godown, $this> */
    public function fromGodown(): BelongsTo
    {
        return $this->belongsTo(Godown::class, 'from_godown_id');
    }

    /** @return BelongsTo<Godown, $this> */
    public function toGodown(): BelongsTo
    {
        return $this->belongsTo(Godown::class, 'to_godown_id');
    }

    /** @return BelongsTo<ProductionBatch, $this> */
    public function toBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'to_batch_id');
    }
}
