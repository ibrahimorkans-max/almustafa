<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Product;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What the mill wants to buy, broadcast to chosen brokers / farmers / traders.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $product_id
 * @property string $quantity_kg
 * @property int|null $target_rate_paise_per_quintal
 * @property array<string, int>|null $quality_spec
 * @property Carbon|null $delivery_from
 * @property Carbon|null $delivery_to
 * @property string $status
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property string|null $notes
 * @property int $version
 * @property-read Product $product
 */
class PaddyRequirement extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'number', 'product_id', 'quantity_kg', 'target_rate_paise_per_quintal', 'quality_spec',
        'delivery_from', 'delivery_to', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'quantity_kg' => 'decimal:3',
            'target_rate_paise_per_quintal' => 'integer',
            'quality_spec' => 'array',
            'delivery_from' => 'date',
            'delivery_to' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<RequirementRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(RequirementRecipient::class, 'requirement_id');
    }

    /** @return HasMany<Quote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'requirement_id');
    }
}
