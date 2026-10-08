<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one vehicle is to load against a sales sauda, and from which godown.
 * pending_credit (over the buyer's limit, waiting for an override) → open → loading (vehicle at the
 * gate) → dispatched (slip issued) / cancelled.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $sauda_id
 * @property string $party_id
 * @property string $product_id
 * @property string $godown_id
 * @property string $quantity_kg
 * @property int|null $bag_count
 * @property string|null $bag_weight_kg
 * @property int $estimated_value_paise
 * @property string $status
 * @property string|null $credit_override_request_id
 * @property string|null $gate_entry_id
 * @property string|null $note
 * @property string|null $created_by
 * @property int $version
 * @property-read Sauda $sauda
 * @property-read Party $party
 * @property-read Product $product
 * @property-read Godown $godown
 * @property-read GateEntry|null $gateEntry
 */
class DispatchOrder extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const PENDING_CREDIT = 'pending_credit';

    public const OPEN = 'open';

    public const LOADING = 'loading';

    public const DISPATCHED = 'dispatched';

    public const CANCELLED = 'cancelled';

    /** Statuses whose value still counts against the buyer's credit (not yet billed). */
    public const EXPOSED = [self::PENDING_CREDIT, self::OPEN, self::LOADING];

    protected $fillable = [
        'number', 'sauda_id', 'party_id', 'product_id', 'godown_id', 'quantity_kg', 'bag_count', 'bag_weight_kg',
        'estimated_value_paise', 'note',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return [
            'quantity_kg' => 'decimal:3', 'bag_count' => 'integer', 'bag_weight_kg' => 'decimal:3',
            'estimated_value_paise' => 'integer', 'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Sauda, $this> */
    public function sauda(): BelongsTo
    {
        return $this->belongsTo(Sauda::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
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

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }
}
