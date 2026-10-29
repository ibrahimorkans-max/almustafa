<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One payment against a labour contractor's bill (a bill may be paid in parts). Append-only; its payment
 * voucher debits the contractor and credits the cash or bank ledger it was paid from.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $contractor_bill_id
 * @property Carbon $paid_on
 * @property int $amount_paise
 * @property string $mode cash | bank
 * @property string $account_id
 * @property string|null $reference
 * @property string|null $voucher_id
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read ContractorBill $bill
 * @property-read LedgerAccount $account
 * @property-read Voucher|null $voucher
 */
class ContractorBillPayment extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['contractor_bill_id', 'paid_on', 'amount_paise', 'mode', 'account_id', 'reference', 'voucher_id', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount_paise' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<ContractorBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(ContractorBill::class, 'contractor_bill_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
