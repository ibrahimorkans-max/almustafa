<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunAttendance;
use Illuminate\Database\Eloquent\Model;

/**
 * Attendance summary slip of one employee and month, from the attendance the payroll was locked
 * with (the latest lock), so it always agrees with the payslip. Final once that payroll is finalized.
 */
final class AttendanceSummaryDocument extends BaseDocument
{
    public function key(): string
    {
        return 'attendance_summary';
    }

    public function label(): string
    {
        return 'Attendance summary';
    }

    public function sourceClass(): string
    {
        return PayrollRunAttendance::class;
    }

    public function isFinal(Model $source): bool
    {
        /** @var PayrollRunAttendance $source */
        $run = PayrollRun::query()->findOrFail($source->payroll_run_id);

        return $run->status === PayrollRun::FINALIZED && $source->lock_no === $run->lock_no;
    }

    public function number(Model $source): ?string
    {
        return null;
    }

    public function series(): string
    {
        return 'ATS';
    }

    public function employeeId(Model $source): string
    {
        /** @var PayrollRunAttendance $source */
        return $source->employee_id;
    }

    public function defaultPaper(): string
    {
        return 'half_a4';
    }

    public function build(Model $source, array $options): array
    {
        /** @var PayrollRunAttendance $source */
        $run = PayrollRun::query()->findOrFail($source->payroll_run_id);
        $e = Employee::query()->with(['designation', 'department', 'person'])->findOrFail($source->employee_id);
        $t = $source->totals;
        $n = fn (string $k) => ['n' => (string) ($t[$k] ?? 0)];

        return [
            'title' => 'attendance_summary',
            'date' => ($run->finalized_at ?? now())->timezone('Asia/Karachi')->toDateString(),
            'period' => $run->month,
            'fields' => [['month', ['ym' => $run->month]], ...$this->employeeFields($e)],
            'tables' => [[
                'k' => 'attendance_calendar', 'cols' => ['item', 'quantity'], 'rows' => [
                    [['k' => 'present'], $n('present')], [['k' => 'paid_leave'], $n('paid_leave')], [['k' => 'unpaid_leave'], $n('unpaid_leave')],
                    [['k' => 'absent'], $n('absent')], [['k' => 'holidays'], $n('holidays')], [['k' => 'weekly_offs'], $n('weekly_offs')],
                    [['k' => 'late_minutes'], $n('late_minutes')], [['k' => 'overtime_hours'], ['n' => number_format(((int) ($t['overtime_minutes'] ?? 0)) / 60, 1)]],
                ],
            ]],
            'extras' => ['calendar' => array_map(fn (array $d) => [(int) substr((string) $d['date'], 8, 2), (string) $d['code']], $source->days)],
            'signatures' => ['hr_manager', 'employee_signature'],
        ];
    }
}
