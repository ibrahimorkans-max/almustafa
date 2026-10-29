<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Domain\Audit\Auditable;
use App\Domain\Security\RecordsEntryLocation;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Godown;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A paddy receipt booked into a godown: entered by hand (own crop, a lot that came without the
 * weighbridge, an opening stock) or against a gate token. Gross − tare = net; net − deduction =
 * accepted weight, which posting adds to stock (one stock ledger row, never twice). A token has at most
 * one stock entry, and a token whose gate slip already booked the paddy cannot get one.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property Carbon $entry_date
 * @property string $product_id
 * @property string|null $paddy_variety_id
 * @property string|null $party_id
 * @property string|null $party_name
 * @property string $godown_id
 * @property string|null $registration_no
 * @property string|null $gate_entry_id
 * @property string|null $sauda_id
 * @property int|null $bag_count
 * @property string $gross_kg
 * @property string $tare_kg
 * @property string $net_kg
 * @property string $deduction_kg
 * @property string $accepted_kg
 * @property list<array<string, mixed>>|null $deduction_lines
 * @property string|null $deduction_override_reason
 * @property int|null $rate_paise_per_maund
 * @property int|null $amount_paise
 * @property string|null $remarks
 * @property string $status
 * @property string|null $stock_entry_id
 * @property Carbon|null $posted_at
 * @property string|null $posted_by
 * @property-read Product $product
 * @property-read Godown $godown
 * @property array{lat: float, lng: float, accuracy_m: int|null, at: string}|null $captured_location where it was entered (EntryLocation)
 */
class PaddyStockEntry extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use RecordsEntryLocation;
    use TracksCreator;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    public const STATUS_LABELS = [self::DRAFT => 'Draft', self::POSTED => 'Posted to stock', self::CANCELLED => 'Cancelled'];

    protected $fillable = [
        'entry_date', 'product_id', 'paddy_variety_id', 'party_id', 'party_name', 'godown_id', 'registration_no', 'gate_entry_id',
        'sauda_id', 'bag_count', 'gross_kg', 'tare_kg', 'net_kg', 'deduction_kg', 'accepted_kg', 'deduction_lines',
        'deduction_override_reason', 'rate_paise_per_maund', 'amount_paise', 'remarks',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date', 'gross_kg' => 'decimal:3', 'tare_kg' => 'decimal:3', 'net_kg' => 'decimal:3',
            'deduction_kg' => 'decimal:3', 'accepted_kg' => 'decimal:3', 'deduction_lines' => 'array', 'bag_count' => 'integer',
            'rate_paise_per_maund' => 'integer', 'amount_paise' => 'integer', 'posted_at' => 'datetime', 'version' => 'integer',
        ];
    }

    public function partyLabel(): ?string
    {
        return $this->party->name ?? $this->party_name;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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

    /** @return BelongsTo<Sauda, $this> */
    public function sauda(): BelongsTo
    {
        return $this->belongsTo(Sauda::class);
    }

    /** @return BelongsTo<StockEntry, $this> */
    public function stockEntry(): BelongsTo
    {
        return $this->belongsTo(StockEntry::class);
    }
}
