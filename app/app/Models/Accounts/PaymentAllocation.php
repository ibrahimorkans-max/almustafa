<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which bills a payment settles (frozen with the request).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $payment_id
 * @property string $purchase_bill_id
 * @property int $amount_paise
 * @property-read PurchaseBill $bill
 * @property-read Payment $payment
 */
class PaymentAllocation extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['payment_id', 'purchase_bill_id', 'amount_paise'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }

    /** @return BelongsTo<PurchaseBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
