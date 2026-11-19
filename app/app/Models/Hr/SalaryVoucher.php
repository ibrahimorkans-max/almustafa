<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\LedgerAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An employee's salary (or bonus / other payment) paid on the spot (App\Domain\Hr\SalaryVouchers). Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $employee_id
 * @property string $kind salary | bonus | other
 * @property string|null $month
 * @property string|null $days
 * @property int $gross_paise
 * @property int $advance_paise
 * @property string|null $advance_id
 * @property int $other_deduction_paise
 * @property string|null $other_deduction_note
 * @property int $net_paise
 * @property string $mode
 * @property string $ledger_id
 * @property string|null $reference
 * @property Carbon $paid_at
 * @property string|null $voucher_id
 * @property string|null $note
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Employee $employee
 * @property-read LedgerAccount $ledger
 * @property-read Advance|null $advance
 */
class SalaryVoucher extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const KINDS = ['salary' => 'Salary', 'bonus' => 'Bonus', 'other' => 'Other payment'];

    public const MODES = ['cash' => 'Cash', 'cheque' => 'Cheque', 'ibft' => 'IBFT (online transfer)', 'raast' => 'Raast',
        'mobile_wallet' => 'JazzCash / Easypaisa', 'other' => 'Other'];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'created_at' => 'datetime', 'gross_paise' => 'integer', 'advance_paise' => 'integer',
            'other_deduction_paise' => 'integer', 'net_paise' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_id');
    }

    /** @return BelongsTo<Advance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class);
    }
}
