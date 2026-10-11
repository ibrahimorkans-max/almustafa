<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string $leave_type_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property string $half_day none|first|second
 * @property string $days
 * @property string $reason
 * @property string $status pending|approved|rejected|cancelled
 * @property string $requested_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 */
class LeaveRequest extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['employee_id', 'leave_type_id', 'from_date', 'to_date', 'half_day', 'days', 'reason', 'status', 'requested_by'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'days' => 'decimal:1', 'decided_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
