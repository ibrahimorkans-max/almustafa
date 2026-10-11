<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An offer and its negotiation. The current terms are a projection of the latest revision;
 * every offer / counter-offer is kept in quote_revisions.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string|null $requirement_id
 * @property string $party_id
 * @property string|null $broker_party_id
 * @property string $product_id
 * @property string $status
 * @property int $current_revision
 * @property string $quantity_kg
 * @property int $rate_paise_per_maund
 * @property string $last_offer_by
 * @property Carbon|null $valid_until
 * @property string|null $sauda_id
 * @property int $version
 * @property-read Party $party
 * @property-read Party|null $broker
 * @property-read Product $product
 * @property-read PaddyRequirement|null $requirement
 */
class Quote extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_COUNTERED = 'countered';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_EXPIRED = 'expired';

    public const BY_MILL = 'mill';

    public const BY_SELLER = 'seller';

    protected $fillable = [
        'number', 'requirement_id', 'party_id', 'broker_party_id', 'product_id', 'quantity_kg',
        'rate_paise_per_maund', 'last_offer_by', 'valid_until',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_SUBMITTED, 'current_revision' => 1];

    protected function casts(): array
    {
        return [
            'quantity_kg' => 'decimal:3',
            'rate_paise_per_maund' => 'integer',
            'current_revision' => 'integer',
            'valid_until' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_COUNTERED], true)
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'broker_party_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<PaddyRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(PaddyRequirement::class, 'requirement_id');
    }

    /** @return HasMany<QuoteRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuoteRevision::class)->orderBy('revision');
    }
}
