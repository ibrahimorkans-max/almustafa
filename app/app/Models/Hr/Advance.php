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
 * A salary advance (recovered in a few months) or a staff loan (longer). Requested by HR, approved
 * by a different person, disbursed from cash or bank, recovered in payroll or repaid in cash. The
 * balance is always amount − recoveries (append-only), never a typed number.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string|null $number
 * @property string $kind advance|loan
 * @property int $amount_paise
 * @property int $installment_paise
 * @property string $start_month Y-m
 * @property string $reason
 * @property string $status requested|approved|rejected|disbursed|closed
 * @property string|null $requested_by
 * @property string|null $approved_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property string|null $paid_from_ledger_id
 * @property string|null $voucher_id
 * @property Carbon|null $disbursed_at
 * @property int $version
 * @property-read Employee $employee
 * @property-read LedgerAccount|null $paidFrom
 */
class Advance extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const KINDS = ['advance' => 'Salary advance', 'loan' => 'Staff loan'];

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const DISBURSED = 'disbursed';

    public const CLOSED = 'closed';

    protected $fillable = ['employee_id', 'kind', 'amount_paise', 'installment_paise', 'start_month', 'reason', 'requested_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::REQUESTED];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer', 'installment_paise' => 'integer', 'decided_at' => 'datetime',
            'disbursed_at' => 'datetime', 'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'paid_from_ledger_id');
    }

    /** @return HasMany<AdvanceRecovery, $this> */
    public function recoveries(): HasMany
    {
        return $this->hasMany(AdvanceRecovery::class);
    }

    public function recoveredPaise(): int
    {
        return (int) $this->recoveries()->sum('amount_paise');
    }

    public function balancePaise(): int
    {
        return $this->status === self::DISBURSED || $this->status === self::CLOSED ? $this->amount_paise - $this->recoveredPaise() : 0;
    }
}
