<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Domain\Audit\Auditable;
use App\Domain\Sync\FeedsSync;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use App\Support\Values\Weight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A deal. Once approved, its commercial terms are frozen: the record shows exactly what
 * was agreed. Lifecycle changes go through SaudaService and are logged in sauda_events.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $direction
 * @property string $status
 * @property string $party_id
 * @property string|null $broker_party_id
 * @property string $product_id
 * @property string $quantity_kg
 * @property int $tolerance_bp
 * @property int $rate_paise_per_maund
 * @property int $brokerage_paise_per_maund
 * @property array<string, int>|null $quality_terms
 * @property Carbon|null $delivery_from
 * @property Carbon|null $delivery_to
 * @property int $payment_days
 * @property string|null $quote_id
 * @property string|null $requirement_id
 * @property string $delivered_kg
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $closed_at
 * @property string|null $close_reason
 * @property string|null $created_by
 * @property int $version
 * @property Carbon|null $archived_at
 * @property Carbon $updated_at
 * @property-read Party $party
 * @property-read Party|null $broker
 * @property-read Product $product
 */
class Sauda extends Model
{
    use Auditable;
    use BelongsToEntity;
    use FeedsSync;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const SYNC_TYPE = 'sauda';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_IN_DELIVERY = 'in_delivery';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /** Terms that may never change after approval. */
    public const FROZEN_TERMS = [
        'party_id', 'broker_party_id', 'product_id', 'quantity_kg', 'tolerance_bp', 'rate_paise_per_maund',
        'brokerage_paise_per_maund', 'quality_terms', 'delivery_from', 'delivery_to', 'payment_days', 'direction',
    ];

    protected $fillable = [
        'number', 'direction', 'party_id', 'broker_party_id', 'product_id', 'quantity_kg', 'tolerance_bp',
        'rate_paise_per_maund', 'brokerage_paise_per_maund', 'quality_terms', 'delivery_from', 'delivery_to',
        'payment_days', 'quote_id', 'requirement_id', 'paddy_variety_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_DRAFT, 'direction' => 'purchase', 'delivered_kg' => '0.000', 'tolerance_bp' => 500];

    protected function casts(): array
    {
        return [
            'quantity_kg' => 'decimal:3',
            'delivered_kg' => 'decimal:3',
            'tolerance_bp' => 'integer',
            'rate_paise_per_maund' => 'integer',
            'brokerage_paise_per_maund' => 'integer',
            'quality_terms' => 'array',
            'delivery_from' => 'date',
            'delivery_to' => 'date',
            'payment_days' => 'integer',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Sauda $sauda): void {
            if ($sauda->getOriginal('approved_at') !== null && $sauda->isDirty(self::FROZEN_TERMS)) {
                throw new LogicException('An approved sauda\'s terms cannot be changed. Cancel it and create a new one.');
            }
        });
    }

    public function isOpenForDelivery(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_IN_DELIVERY], true);
    }

    public function remaining(): Weight
    {
        $left = Weight::kg($this->quantity_kg)->minus(Weight::kg($this->delivered_kg));

        return $left->isNegative() ? Weight::zero() : $left;
    }

    /** Lowest delivered quantity that completes the deal (quantity − tolerance). */
    public function completionThreshold(): Weight
    {
        return Weight::kg(bcdiv(bcmul($this->quantity_kg, (string) (10000 - $this->tolerance_bp), 3), '10000', 3));
    }

    public function maximumQuantity(): Weight
    {
        return Weight::kg(bcdiv(bcmul($this->quantity_kg, (string) (10000 + $this->tolerance_bp), 3), '10000', 3));
    }

    /**
     * Brokers / agents see only deals they brokered.
     *
     * @param  Builder<Sauda>  $query
     */
    public function scopeVisibleTo(Builder $query, LoginAccount $viewer, ?string $direction = null): void
    {
        // Sales deals belong to the sales desk: 'sales.view' sees them all, as 'deal.view' does.
        if ($viewer->hasPermission('deal.view') || ($direction === 'sale' && $viewer->hasPermission('sales.view'))) {
            return;
        }
        if ($viewer->hasPermission('deal.view_own')) {
            $query->whereIn('broker_party_id', Party::query()->where('login_account_id', $viewer->id)->select('id'));

            return;
        }
        $query->whereRaw('1 = 0');
    }

    /** @return BelongsTo<PaddyVariety, $this> */
    public function paddyVariety(): BelongsTo
    {
        return $this->belongsTo(PaddyVariety::class);
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

    /** @return HasMany<SaudaEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SaudaEvent::class)->orderBy('occurred_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeWithArchived(Builder $query): void
    {
        // Saudas are never hidden by default in phase 3; archiving arrives in phase 7.
    }

    /** Gate devices need party/product/quantity to match arrivals; rates only for deal viewers. */
    public function toSyncArray(LoginAccount $viewer): array
    {
        $data = [
            'id' => $this->id, 'version' => $this->version, 'number' => $this->number, 'status' => $this->status,
            'direction' => $this->direction, 'party_id' => $this->party_id, 'broker_party_id' => $this->broker_party_id,
            'product_id' => $this->product_id, 'quantity_kg' => $this->quantity_kg, 'delivered_kg' => $this->delivered_kg,
            'delivery_from' => $this->delivery_from?->toDateString(), 'delivery_to' => $this->delivery_to?->toDateString(),
            'quality_terms' => $this->quality_terms,
        ];
        if ($viewer->hasPermission('deal.view')) {
            $data['rate_paise_per_maund'] = $this->rate_paise_per_maund;
        }

        return $data;
    }
}
