<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Models\Hr\Employee;
use Carbon\CarbonImmutable;

/** Month × employees register built from AttendanceCalculator, for the screen and the CSV. */
final class AttendanceRegister
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{month: string, dates: list<string>, rows: list<array{code: string, name: string, days: list<string>, totals: array<string, int|float>}>}
     */
    public function month(string $month, ?string $departmentId = null): array
    {
        $from = CarbonImmutable::parse($month.'-01', 'Asia/Karachi')->startOfMonth();
        $to = $from->endOfMonth()->startOfDay();
        $dates = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $rows = [];
        $employees = Employee::query()->with(['person', 'shift'])->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->where('joining_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('exited_on')->orWhere('exited_on', '>=', $from->toDateString()))
            ->orderBy('code')->get();
        foreach ($employees as $e) {
            $r = $this->calculator->period($e, $from, $to);
            $rows[] = ['code' => $e->code, 'name' => $e->name(), 'days' => array_map(fn (array $d) => $d['code'], $r['days']), 'totals' => $r['totals']];
        }

        return ['month' => $from->format('Y-m'), 'dates' => $dates, 'rows' => $rows];
    }

    public function csv(string $month, ?string $departmentId = null): string
    {
        $m = $this->month($month, $departmentId);
        $out = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot build the file.');
        fputcsv($out, ['code', 'name', ...array_map(fn ($d) => substr($d, 8, 2), $m['dates']),
            'present', 'paid_leave', 'unpaid_leave', 'absent', 'holidays', 'weekly_offs', 'missing_out', 'late_min', 'overtime_min'], escape: '');
        foreach ($m['rows'] as $r) {
            $t = $r['totals'];
            fputcsv($out, [$r['code'], self::cell($r['name']), ...$r['days'], $t['present'], $t['paid_leave'], $t['unpaid_leave'], $t['absent'],
                $t['holidays'], $t['weekly_offs'], $t['incomplete'], $t['late_minutes'], $t['overtime_minutes']], escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->record('report.exported', null, null, null, ['report' => 'attendance_register', 'month' => $m['month'], 'rows' => count($m['rows'])]);

        return $csv;
    }

    private static function cell(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }
}
