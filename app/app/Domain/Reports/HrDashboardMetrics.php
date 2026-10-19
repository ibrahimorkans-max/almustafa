<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Accounts\FinancialReports;
use App\Domain\Hr\Attendance\AttendanceCalculator;
use App\Domain\Tenancy\TenantContext;
use App\Models\DailyWages\DailyWageSheet;
use App\Models\Documents\DocumentAccessLog;
use App\Models\Documents\GeneratedDocument;
use App\Models\Hr\Advance;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Employee;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** Figures for the HR and owner dashboards, for the working entity. */
final class HrDashboardMetrics
{
    public function __construct(
        private readonly AttendanceCalculator $attendance,
        private readonly FinancialReports $reports,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Today's attendance of everyone employed today: present (punched in, late counted separately),
     * on leave, off (weekly off or holiday) and absent (none of these, so far today).
     *
     * @return array{headcount: int, present: int, late: int, on_leave: int, off: int, absent: int}
     */
    public function today(?CarbonImmutable $day = null): array
    {
        $day ??= CarbonImmutable::now('Asia/Karachi')->startOfDay();
        // Every employee's day is worked out from the punches; the dashboard polls, so keep it a minute.
        $key = 'hr-today:'.($this->tenant->entityId() ?? '-').':'.$day->toDateString();

        return Cache::remember($key, 60, fn (): array => $this->countDay($day));
    }

    /** @return array{headcount: int, present: int, late: int, on_leave: int, off: int, absent: int} */
    private function countDay(CarbonImmutable $day): array
    {
        $out = ['headcount' => 0, 'present' => 0, 'late' => 0, 'on_leave' => 0, 'off' => 0, 'absent' => 0];
        $employees = Employee::query()->with('shift')->where('status', '!=', Employee::EXITED)->where('joining_date', '<=', $day->toDateString())->get();
        foreach ($employees as $e) {
            $row = $this->attendance->period($e, $day, $day)['days'][0] ?? null;
            if ($row === null || in_array('not_employed', $row['notes'] ?? [], true)) {
                continue;
            }
            $out['headcount']++;
            if ($row['first_in'] !== null) {
                $out['present']++;
                $out['late'] += ($row['late_minutes'] ?? 0) > 0 ? 1 : 0;
            } elseif ($row['leave_type'] !== null) {
                $out['on_leave']++;
            } elseif (in_array($row['code'], ['WO', 'HO'], true)) {
                $out['off']++;
            } else {
                $out['absent']++;
            }
        }

        return $out;
    }

    /** @return array{flagged_punches: int, leave_pending: int, leave_next_7_days: int, payroll_month: string|null, payroll_status: string|null, advances_outstanding: int, undelivered_payslips: int} */
    public function hr(): array
    {
        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();
        $run = PayrollRun::query()->orderByDesc('month')->first();
        $lastFinal = PayrollRun::query()->where('status', PayrollRun::FINALIZED)->orderByDesc('month')->first();
        $undelivered = 0;
        if ($lastFinal?->finalized_at !== null) {
            // Current payslips issued since that payroll was finalized that nobody has shared, opened or downloaded.
            $undelivered = GeneratedDocument::query()->where('doc_type', 'payslip')->where('created_at', '>=', $lastFinal->finalized_at)
                ->whereNotIn('id', GeneratedDocument::query()->whereNotNull('supersedes_id')->select('supersedes_id'))
                ->whereNotIn('id', DocumentAccessLog::query()->whereIn('action', ['share', 'download', 'view', 'open_link'])->select('generated_document_id'))
                ->count();
        }

        return [
            'flagged_punches' => AttendanceLog::query()->awaitingReview()->count(),
            'leave_pending' => LeaveRequest::query()->where('status', LeaveRequest::PENDING)->count(),
            'leave_next_7_days' => LeaveRequest::query()->where('status', LeaveRequest::APPROVED)
                ->where('from_date', '<=', $today->addDays(7)->toDateString())->where('to_date', '>=', $today->toDateString())->count(),
            'payroll_month' => $run?->month, 'payroll_status' => $run?->status,
            'advances_outstanding' => (int) Advance::query()->where('status', Advance::DISBURSED)->get()->sum(fn (Advance $a) => $a->balancePaise()),
            'undelivered_payslips' => $undelivered,
        ];
    }

    /**
     * The owner's month so far: payroll cost, profit (from the books) and milling cost per maund.
     *
     * @return array{month: string, payroll_cost: int, net_profit: int, sales: int, cost_per_maund: int|null, maunds: string, daily_wages: int, daily_wages_owed: int}
     */
    public function owner(): array
    {
        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();
        $from = $today->startOfMonth();
        $pl = $this->reports->profitAndLoss($from, $today);
        $cpm = $this->reports->costPerMaund($from, $today);

        return [
            'month' => $today->format('M Y'), 'payroll_cost' => $pl['payroll_cost'], 'net_profit' => $pl['net_profit'],
            'sales' => $pl['sales']['total'] + $pl['direct_incomes']['total'], 'cost_per_maund' => $cpm['per_maund'], 'maunds' => $cpm['maunds'],
            'daily_wages' => (int) DailyWageSheet::query()->whereIn('status', [DailyWageSheet::POSTED, DailyWageSheet::PAID])
                ->where('work_date', '>=', $from->toDateString())->sum('net_paise'),
            'daily_wages_owed' => (int) DailyWageSheet::query()->where('status', DailyWageSheet::POSTED)->sum('net_paise'),
        ];
    }
}
