<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An allowance paid by rule on top of the salary structure: a fixed amount (prorated or not), a
 * percentage of Basic earned, an amount per day present, or a fixed amount conditional on
 * attendance (e.g. attendance allowance only with at most one absence). Optionally limited to
 * departments or employment types, and to a date range.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $method fixed|pct_basic|per_day|attendance
 * @property int $amount_paise
 * @property int $rate_bp
 * @property bool $prorate
 * @property int|null $max_absent_days
 * @property int|null $max_late_days
 * @property list<string>|null $departments
 * @property list<string>|null $employment_types
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $status
 */
class AllowanceRule extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const METHODS = [
        'fixed' => 'Fixed amount per month', 'pct_basic' => 'Percentage of Basic earned',
        'per_day' => 'Amount per day present', 'attendance' => 'Fixed amount if attendance is good',
    ];

    protected $fillable = [
        'code', 'name', 'method', 'amount_paise', 'rate_bp', 'prorate', 'max_absent_days', 'max_late_days',
        'departments', 'employment_types', 'effective_from', 'effective_to', 'status',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer', 'rate_bp' => 'integer', 'prorate' => 'boolean', 'max_absent_days' => 'integer',
            'max_late_days' => 'integer', 'departments' => 'array', 'employment_types' => 'array',
            'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer',
        ];
    }

    public function appliesTo(Employee $employee): bool
    {
        return ($this->departments === null || $this->departments === [] || in_array($employee->department_id, $this->departments, true))
            && ($this->employment_types === null || $this->employment_types === [] || in_array($employee->employment_type, $this->employment_types, true));
    }
}
