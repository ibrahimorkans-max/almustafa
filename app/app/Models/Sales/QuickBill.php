<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\Voucher;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use App\Models\Procurement\Sauda;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bill made on the spot (App\Domain\Sales\QuickBills): a sale, a paddy purchase, rice given for paddy, milling for
 * labour, or a weighing — with its lines, charges and payments.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $kind
 * @property Carbon $billed_at
 * @property string|null $party_id
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $sauda_id
 * @property string|null $gate_entry_id
 * @property string|null $vehicle_type
 * @property string|null $vehicle_no
 * @property string|null $driver_name
 * @property string|null $first_weight_kg
 * @property string|null $second_weight_kg
 * @property int $out_paise
 * @property int $in_paise
 * @property int $service_paise
 * @property int $charges_add_paise
 * @property int $charges_less_paise
 * @property int $net_paise
 * @property int $paid_paise
 * @property string $status
 * @property string|null $voucher_id
 * @property string|null $note
 * @property string|null $created_by
 * @property string|null $sauda_kg
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 * @property-read Party|null $party
 * @property-read Sauda|null $sauda
 * @property-read GateEntry|null $gateEntry
 * @property-read Voucher|null $voucher
 */
class QuickBill extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const KINDS = [
        'sale' => 'Sale',
        'purchase' => 'Purchase (paddy)',
        'exchange' => 'Rice for paddy (hisaab)',
        'milling' => 'Milling for labour (mazdoori)',
        'weighing' => 'Weighing only',
    ];

    public const VEHICLES = [
        'truck' => 'Truck', 'tractor_trolley' => 'Tractor trolley', 'mazda' => 'Mazda', 'pickup' => 'Pickup',
        'loader_rickshaw' => 'Loader rickshaw', 'rickshaw' => 'Rickshaw', 'motorcycle' => 'Motorcycle (bike)',
        'cart' => 'Cart (rehri)', 'by_hand' => 'By hand (no vehicle)', 'other' => 'Other',
    ];

    public const PAYMENT_MODES = [
        'cash' => 'Cash', 'cheque' => 'Cheque', 'ibft' => 'IBFT (online transfer)', 'raast' => 'Raast', 'rtgs' => 'RTGS',
        'pay_order' => 'Pay order', 'card' => 'Card / POS', 'mobile_wallet' => 'JazzCash / Easypaisa', 'other' => 'Other',
    ];

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'billed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'out_paise' => 'integer', 'in_paise' => 'integer', 'service_paise' => 'integer',
            'charges_add_paise' => 'integer', 'charges_less_paise' => 'integer',
            'net_paise' => 'integer', 'paid_paise' => 'integer',
        ];
    }

    /** paid | partial | unpaid (nothing to pay counts as paid). */
    public function paymentStatus(): string
    {
        $due = abs($this->net_paise);

        return match (true) {
            $due === 0 || $this->paid_paise >= $due => 'paid',
            $this->paid_paise > 0 => 'partial',
            default => 'unpaid',
        };
    }

    /** What is still to be paid (either way). */
    public function balancePaise(): int
    {
        return max(0, abs($this->net_paise) - $this->paid_paise);
    }

    /** Who the bill is for: the party, or the walk-in customer's name. */
    public function customer(): string
    {
        return $this->party->name ?? ($this->customer_name ?: 'Walk-in customer');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
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

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return HasMany<QuickBillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuickBillLine::class)->orderBy('line_no');
    }

    /** @return HasMany<QuickBillCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(QuickBillCharge::class);
    }

    /** @return HasMany<QuickBillPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(QuickBillPayment::class)->orderBy('paid_at');
    }
}
