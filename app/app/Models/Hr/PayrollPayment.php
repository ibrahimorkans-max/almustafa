<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\LedgerAccount;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Salary paid to one employee (bank transfer, cash or cheque), posted to the books. Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $payroll_run_id
 * @property string $payroll_item_id
 * @property string $employee_id
 * @property string $number
 * @property int $amount_paise
 * @property string $mode bank|cash|cheque
 * @property string $ledger_account_id
 * @property string|null $bank_file_id
 * @property string|null $iban
 * @property string|null $reference
 * @property Carbon $paid_on
 * @property string|null $voucher_id
 * @property Carbon $created_at
 * @property-read PayrollItem $item
 * @property-read LedgerAccount $ledger
 * @property-read PayrollPaymentAck|null $ack
 */
class PayrollPayment extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const MODES = ['bank' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];

    protected $fillable = [
        'payroll_run_id', 'payroll_item_id', 'employee_id', 'number', 'amount_paise', 'mode', 'ledger_account_id',
        'bank_file_id', 'iban', 'reference', 'paid_on', 'voucher_id', 'created_at',
    ];

    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'iban' => 'encrypted', 'paid_on' => 'date', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<PayrollItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return HasOne<PayrollPaymentAck, $this> */
    public function ack(): HasOne
    {
        return $this->hasOne(PayrollPaymentAck::class);
    }
}
