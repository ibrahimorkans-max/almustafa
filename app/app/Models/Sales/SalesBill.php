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
 * Sales tax invoice (or a commercial invoice when the mill has no STRN) for one outbound slip, computed
 * from the slip and the frozen sales sauda terms.
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
 * @property int $rate_paise_per_maund
 * @property string $invoice_type sales_tax | commercial
 * @property string|null $pct_code
 * @property int $tax_rate_bp
 * @property bool $tax_exempt
 * @property int $further_tax_rate_bp
 * @property string|null $seller_strn
 * @property string|null $buyer_strn
 * @property string|null $buyer_ntn
 * @property int $taxable_paise
 * @property int $sales_tax_paise
 * @property int $further_tax_paise
 * @property int $round_off_paise
 * @property int $total_paise
 * @property string $status
 * @property string|null $voucher_id
 * @property array<string, mixed> $snapshot
 * @property string|null $note
 * @property Carbon|null $posted_at
 * @property int $version
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
        'rate_paise_per_maund', 'invoice_type', 'pct_code', 'tax_rate_bp', 'tax_exempt', 'further_tax_rate_bp', 'seller_strn', 'buyer_strn', 'buyer_ntn',
        'taxable_paise', 'sales_tax_paise', 'further_tax_paise', 'round_off_paise', 'total_paise', 'snapshot',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date', 'due_date' => 'date', 'quantity_kg' => 'decimal:3', 'rate_paise_per_maund' => 'integer',
            'tax_rate_bp' => 'integer', 'tax_exempt' => 'boolean', 'further_tax_rate_bp' => 'integer', 'taxable_paise' => 'integer',
            'sales_tax_paise' => 'integer', 'further_tax_paise' => 'integer', 'round_off_paise' => 'integer', 'total_paise' => 'integer',
            'snapshot' => 'array', 'posted_at' => 'datetime', 'version' => 'integer',
        ];
    }

    public function isSalesTaxInvoice(): bool
    {
        return $this->invoice_type === 'sales_tax';
    }

    public function taxTotal(): int
    {
        return $this->sales_tax_paise + $this->further_tax_paise;
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
