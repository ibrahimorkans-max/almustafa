<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of a leave balance. Append-only: the balance is always the sum of the entries.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string $leave_type_id
 * @property int $year
 * @property string $kind opening|accrual|taken|reversal|carry_forward|lapse|adjustment
 * @property string $days signed
 * @property string|null $leave_request_id
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read LeaveType $leaveType
 */
class LeaveLedgerEntry extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const KINDS = ['opening', 'accrual', 'taken', 'reversal', 'carry_forward', 'lapse', 'adjustment'];

    protected $table = 'leave_ledger';

    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'kind', 'days', 'leave_request_id', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'days' => 'decimal:1', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
