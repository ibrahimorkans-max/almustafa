<?php

declare(strict_types=1);

namespace App\Models\Inventory;

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
 * One stock movement (append-only). Written only by StockLedger, together with the balance.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $product_id
 * @property string $godown_id
 * @property string $quantity_kg signed
 * @property string $movement
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $batch_id
 * @property string|null $reverses_id
 * @property string|null $note
 * @property string|null $created_by
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read Product $product
 * @property-read Godown $godown
 */
class StockEntry extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const RECEIPT = 'receipt';

    public const PRODUCTION_ISSUE = 'production_issue';

    public const PRODUCTION_OUTPUT = 'production_output';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const ADJUSTMENT = 'adjustment';

    public const REVERSAL = 'reversal';

    public const DISPATCH = 'dispatch';

    protected $table = 'stock_ledger';

    protected $fillable = [
        'entity_id', 'product_id', 'godown_id', 'quantity_kg', 'movement', 'source_type', 'source_id',
        'batch_id', 'reverses_id', 'note', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['quantity_kg' => 'decimal:3', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
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
