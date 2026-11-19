<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\LedgerAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received for (or paid out on) a quick bill: any mode (cash, cheque, bank, wallet …), any amount, as many times
 * as needed. Received money is posted at once (receipt voucher); paid-out money is a payment request (PaymentService).
 *
 * @property string $id
 * @property string $quick_bill_id
 * @property string $direction in | out
 * @property string $mode
 * @property string $ledger_id
 * @property int $amount_paise
 * @property string|null $reference
 * @property Carbon $paid_at
 * @property string|null $voucher_id
 * @property string|null $payment_id
 * @property-read LedgerAccount $ledger
 */
class QuickBillPayment extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_id');
    }
}
