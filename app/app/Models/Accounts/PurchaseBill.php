<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Purchase bill for one weighment slip, computed from the frozen slip and sauda terms.
 * Drafted automatically; posting creates the purchase voucher. Amounts are never typed in.
 *
 * @property string $id
 * @property string $entity_id
 * @property string|null $number
 * @property string $gate_entry_id
 * @property string $sauda_id
 * @property string $party_id
 * @property string|null $broker_party_id
 * @property string $product_id
 * @property Carbon $bill_date
 * @property Carbon $due_date
 * @property string $payable_kg
 * @property int $rate_paise_per_maund
 * @property int $gross_paise
 * @property int $rate_cut_paise
 * @property int $brokerage_paise
 * @property int $round_off_paise
 * @property int $net_paise
 * @property int $wht_paise
 * @property int $wht_rate_bp
 * @property int $wht_base_paise
 * @property bool $wht_filer
 * @property string $status
 * @property string|null $voucher_id
 * @property array<string, mixed> $snapshot
 * @property string|null $note
 * @property string|null $posted_by
 * @property Carbon|null $posted_at
 * @property int $version
 * @property-read Party $party
 * @property-read Party|null $broker
 * @property-read Sauda $sauda
 * @property-read GateEntry $gateEntry
 * @property-read Product $product
 */
class PurchaseBill extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'gate_entry_id', 'sauda_id', 'party_id', 'broker_party_id', 'product_id', 'bill_date', 'due_date', 'payable_kg',
        'rate_paise_per_maund', 'gross_paise', 'rate_cut_paise', 'brokerage_paise', 'round_off_paise', 'net_paise', 'snapshot',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date', 'due_date' => 'date', 'payable_kg' => 'decimal:3', 'rate_paise_per_maund' => 'integer',
            'gross_paise' => 'integer', 'rate_cut_paise' => 'integer', 'brokerage_paise' => 'integer', 'round_off_paise' => 'integer',
            'net_paise' => 'integer', 'snapshot' => 'array', 'posted_at' => 'datetime', 'version' => 'integer',
            'wht_paise' => 'integer', 'wht_rate_bp' => 'integer', 'wht_base_paise' => 'integer', 'wht_filer' => 'boolean',
        ];
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

    /** @return BelongsTo<Sauda, $this> */
    public function sauda(): BelongsTo
    {
        return $this->belongsTo(Sauda::class);
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
