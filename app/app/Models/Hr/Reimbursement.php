<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\LedgerAccount;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A staff expense claim (fuel, travel, small purchases paid from the employee's pocket): lines with
 * receipt photos, checked by a supervisor, approved by accounts (posted as an expense owed to the
 * employee), then paid in cash or by bank.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $employee_id
 * @property Carbon $claim_date
 * @property int $total_paise
 * @property string $purpose
 * @property string $status submitted|checked|approved|paid|rejected
 * @property string|null $submitted_by
 * @property string|null $checked_by
 * @property Carbon|null $checked_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $voucher_id
 * @property string|null $paid_from_ledger_id
 * @property string|null $payment_voucher_id
 * @property Carbon|null $paid_at
 * @property string|null $decision_note
 * @property-read Employee $employee
 * @property-read LedgerAccount|null $paidFrom
 */
class Reimbursement extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const SUBMITTED = 'submitted';

    public const CHECKED = 'checked';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    public const STATUSES = [
        self::SUBMITTED => 'Waiting for supervisor', self::CHECKED => 'Waiting for accounts', self::APPROVED => 'Approved (to pay)',
        self::PAID => 'Paid', self::REJECTED => 'Refused',
    ];

    protected $fillable = ['number', 'employee_id', 'claim_date', 'total_paise', 'purpose', 'submitted_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::SUBMITTED];

    protected function casts(): array
    {
        return [
            'claim_date' => 'date', 'total_paise' => 'integer', 'checked_at' => 'datetime', 'approved_at' => 'datetime',
            'paid_at' => 'datetime', 'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<ReimbursementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ReimbursementLine::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'paid_from_ledger_id');
    }
}
