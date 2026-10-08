<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\ReceiptAllocation;
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
 * GST tax invoice for one outbound slip, computed from the slip and the frozen sales sauda terms.
 * The invoice number is assigned when it is posted (gap-free per financial year).
 *
 * @property string $id
 * @property string $entity_id
 * @property string|null $number
 * @property string $gate_entry_id
 * @property string $dispatch_order_id
 * @property string $sauda_id
 * @property string $party_id
 * @property string $product_id
 * @property Carbon $bill_date
 * @property Carbon $due_date
 * @property string $quantity_kg
 * @property int $rate_paise_per_quintal
 * @property string|null $hsn_code
 * @property int $gst_rate_bp
 * @property string|null $supplier_state
 * @property string|null $place_of_supply
 * @property string|null $buyer_gstin
 * @property int $taxable_paise
 * @property int $cgst_paise
 * @property int $sgst_paise
 * @property int $igst_paise
 * @property int $round_off_paise
 * @property int $total_paise
 * @property string $status
 * @property string|null $voucher_id
 * @property array<string, mixed> $snapshot
 * @property string|null $note
 * @property Carbon|null $posted_at
 * @property int $version
 * @property string|null $irn
 * @property string|null $irn_ack_no
 * @property Carbon|null $irn_ack_at
 * @property string|null $irn_signed_qr
 * @property string|null $einvoice_status
 * @property string|null $eway_bill_no
 * @property Carbon|null $eway_valid_until
 * @property-read Party $party
 * @property-read Product $product
 * @property-read Sauda $sauda
 * @property-read GateEntry $gateEntry
 */
class SalesBill extends Model
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
        'gate_entry_id', 'dispatch_order_id', 'sauda_id', 'party_id', 'product_id', 'bill_date', 'due_date', 'quantity_kg',
        'rate_paise_per_quintal', 'hsn_code', 'gst_rate_bp', 'supplier_state', 'place_of_supply', 'buyer_gstin',
        'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'round_off_paise', 'total_paise', 'snapshot',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date', 'due_date' => 'date', 'quantity_kg' => 'decimal:3', 'rate_paise_per_quintal' => 'integer',
            'gst_rate_bp' => 'integer', 'taxable_paise' => 'integer', 'cgst_paise' => 'integer', 'sgst_paise' => 'integer',
            'igst_paise' => 'integer', 'round_off_paise' => 'integer', 'total_paise' => 'integer', 'snapshot' => 'array',
            'posted_at' => 'datetime', 'version' => 'integer', 'irn_ack_at' => 'datetime', 'eway_valid_until' => 'datetime',
        ];
    }

    public function isInterState(): bool
    {
        return $this->igst_paise > 0 || ($this->supplier_state !== null && $this->place_of_supply !== null && $this->supplier_state !== $this->place_of_supply);
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

    /** @return HasMany<ReceiptAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }
}
