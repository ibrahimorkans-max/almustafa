<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Device;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The phone an employee marks attendance with. One approved device per employee; a new one needs
 * HR approval, and approving it retires the previous one.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string $device_id
 * @property string $status pending|approved|rejected|revoked
 * @property Carbon $requested_at
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read Employee $employee
 * @property-read Device $device
 */
class EmployeeDevice extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REVOKED = 'revoked';

    protected $fillable = ['employee_id', 'device_id', 'status', 'requested_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
