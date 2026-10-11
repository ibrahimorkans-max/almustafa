<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One employee's attendance for the month as frozen when the payroll locked it (append-only).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $payroll_run_id
 * @property int $lock_no
 * @property string $employee_id
 * @property list<array<string, mixed>> $days
 * @property array<string, int|float> $totals
 * @property Carbon $created_at
 */
class PayrollRunAttendance extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $table = 'payroll_run_attendance';

    protected $fillable = ['payroll_run_id', 'lock_no', 'employee_id', 'days', 'totals', 'created_at'];

    protected function casts(): array
    {
        return ['days' => 'array', 'totals' => 'array', 'created_at' => 'datetime'];
    }
}
