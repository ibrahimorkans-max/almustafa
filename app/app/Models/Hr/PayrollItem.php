<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One employee's calculated pay in a payroll run (append-only: recalculating adds a new set with
 * the next calculation number; the run points at the current one). The JSON holds every line with
 * its basis, so the payslip and any later check show exactly what was calculated.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $payroll_run_id
 * @property int $calculation_no
 * @property string $employee_id
 * @property array<string, mixed> $employee
 * @property array<string, mixed> $result
 * @property int $gross_paise
 * @property int $deductions_paise
 * @property int $net_paise
 * @property int $employer_paise
 * @property int $warning_count
 * @property Carbon $created_at
 * @property-read PayrollRun $run
 * @property-read Employee $employeeModel
 * @property-read Payslip|null $payslip
 */
class PayrollItem extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = [
        'payroll_run_id', 'calculation_no', 'employee_id', 'employee', 'result', 'gross_paise', 'deductions_paise',
        'net_paise', 'employer_paise', 'warning_count', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'calculation_no' => 'integer', 'employee' => 'array', 'result' => 'array', 'gross_paise' => 'integer',
            'deductions_paise' => 'integer', 'net_paise' => 'integer', 'employer_paise' => 'integer', 'warning_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** Amount of one pay line (earnings, deductions or employer) by code, 0 when absent. */
    public function line(string $section, string $code): int
    {
        return array_sum(array_map(fn (array $l) => $l['code'] === $code ? (int) $l['amount_paise'] : 0, $this->result[$section] ?? []));
    }

    public function paidPaise(): int
    {
        return (int) $this->payments()->sum('amount_paise');
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employeeModel(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** @return HasOne<Payslip, $this> */
    public function payslip(): HasOne
    {
        return $this->hasOne(Payslip::class);
    }

    /** @return HasMany<PayrollPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
    }
}
