<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Filament\Support\Units;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The provider's invoice to a customer (SaaS billing): monthly for its plan, or another charge. Paid by
 * bank transfer: the customer reports the transfer, the platform admin records the money received.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $number
 * @property string $kind monthly | other
 * @property string $description
 * @property string|null $plan_id
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property int $amount_paise
 * @property string $status open | paid | void
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property Carbon|null $reported_at
 * @property string|null $reported_reference the transaction ID the customer reported (cleaned: no spaces, capitals)
 * @property string|null $reported_method_id which payment method it says it used
 * @property int|null $reported_amount_paise the amount it says it sent
 * @property Carbon|null $reported_paid_on
 * @property string|null $reported_note
 * @property string|null $receipt_path
 * @property string|null $reported_by
 * @property Carbon|null $paid_at
 * @property int|null $paid_amount_paise
 * @property string|null $payment_reference
 * @property string|null $payment_note
 * @property string|null $recorded_by
 * @property string|null $void_reason
 * @property string|null $last_reminder
 * @property-read Customer $customer
 */
class SaasInvoice extends Model
{
    use Auditable;
    use HasUuids;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const VOID = 'void';

    public const MONTHLY = 'monthly';

    public const OTHER = 'other';

    protected $fillable = [
        'customer_id', 'number', 'kind', 'description', 'plan_id', 'period_start', 'period_end', 'amount_paise', 'status',
        'issued_at', 'due_at', 'reported_at', 'reported_reference', 'reported_method_id', 'reported_amount_paise', 'reported_paid_on', 'reported_note', 'receipt_path',
        'reported_by', 'paid_at', 'paid_amount_paise', 'payment_reference', 'payment_note', 'recorded_by', 'void_reason', 'last_reminder',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'amount_paise' => 'integer', 'issued_at' => 'datetime',
            'due_at' => 'datetime', 'reported_at' => 'datetime', 'reported_paid_on' => 'date', 'paid_at' => 'datetime', 'reported_amount_paise' => 'integer',
            'paid_amount_paise' => 'integer',
        ];
    }

    /** @return BelongsTo<SaasPaymentMethod, $this> */
    public function reportedMethod(): BelongsTo
    {
        return $this->belongsTo(SaasPaymentMethod::class, 'reported_method_id');
    }

    /**
     * A transaction ID as it is matched against the bank statement: spaces typed by mistake removed,
     * letters in capitals ("ft 2611 / 77" → "FT2611/77").
     */
    public static function cleanTransactionId(string $id): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', trim($id)));
    }

    /** One line for a card: "Transaction ID FT2611/77 · JazzCash · Rs 35,000.00 · 25 Oct". */
    public function reportedSummary(): ?string
    {
        if ($this->reported_reference === null) {
            return null;
        }

        return implode(' · ', array_filter([
            'Transaction ID '.$this->reported_reference,
            $this->reportedMethod?->name,
            $this->reported_amount_paise !== null ? Units::paiseToRupees($this->reported_amount_paise) : null,
            $this->reported_paid_on?->format('d M Y'),
        ]));
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::OPEN && $this->due_at->isPast();
    }

    /** Calendar days (Pakistan) past the due date: 1 on the day after it; 0 when not yet overdue. */
    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }
        $due = $this->due_at->copy()->timezone('Asia/Karachi')->startOfDay();

        return (int) $due->diffInDays(now('Asia/Karachi')->startOfDay());
    }

    /** open, overdue, paid or void, for a badge. */
    public function standing(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }
}
