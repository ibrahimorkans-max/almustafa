<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Employee;
use App\Models\Hr\Holiday;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\Shift;
use Carbon\CarbonImmutable;

/**
 * Worked days from punches, shifts, holidays and approved leave. Only punches whose final status is
 * valid (or accepted on review) count. A punch belongs to the shift it falls in; a night shift that
 * crosses midnight counts for the day it started. Each day is split into present / paid leave /
 * unpaid leave / absent fractions, so payroll can add them up exactly. Nothing is stored here.
 */
final class AttendanceCalculator
{
    /** Window around the shift in which punches are matched to it. */
    private const BEFORE_MINUTES = 240;

    private const AFTER_MINUTES = 360;

    /**
     * @return array{days: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public function period(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $shift = $employee->shift ?? self::defaultShift();
        $from = $from->setTimezone('Asia/Karachi')->startOfDay();
        $to = $to->setTimezone('Asia/Karachi')->startOfDay();

        $holidays = Holiday::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->keyBy(fn (Holiday $h) => $h->date->toDateString());
        $leaves = LeaveRequest::query()->with('leaveType')->where('employee_id', $employee->id)->where('status', LeaveRequest::APPROVED)
            ->where('from_date', '<=', $to->toDateString())->where('to_date', '>=', $from->toDateString())->get();
        $punches = AttendanceLog::query()->with('reviews')->where('employee_id', $employee->id)
            ->whereBetween('occurred_at', [$from->subDay()->utc()->format('Y-m-d H:i:s.v'), $to->addDays(2)->utc()->format('Y-m-d H:i:s.v')])
            ->orderBy('occurred_at')->get()
            ->filter(fn (AttendanceLog $l) => $l->finalStatus() === AttendanceLog::VALID)->values();

        $joined = CarbonImmutable::parse($employee->joining_date->toDateString(), 'Asia/Karachi');
        $left = $employee->exited_on !== null ? CarbonImmutable::parse($employee->exited_on->toDateString(), 'Asia/Karachi') : null;
        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();

        $days = [];
        $totals = ['present' => 0.0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'absent' => 0.0, 'holidays' => 0, 'weekly_offs' => 0,
            'incomplete' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0, 'worked_minutes' => 0];

        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $row = ['date' => $key, 'code' => '—', 'present' => 0.0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'absent' => 0.0,
                'leave_type' => null, 'first_in' => null, 'last_out' => null, 'worked_minutes' => 0, 'late_minutes' => 0,
                'early_minutes' => 0, 'overtime_minutes' => 0, 'notes' => []];

            if ($d->lessThan($joined) || ($left !== null && $d->greaterThan($left))) {
                $row['notes'][] = 'not_employed';
                $days[] = $row;

                continue;
            }

            [$start, $end] = $shift->windowFor($d);
            $in = $punches->first(fn (AttendanceLog $l) => $l->kind === 'in'
                && $l->occurred_at->between($start->subMinutes(self::BEFORE_MINUTES), $end->addMinutes(self::AFTER_MINUTES)));
            $out = $in === null ? null : $punches->filter(fn (AttendanceLog $l) => $l->kind === 'out'
                && $l->occurred_at->greaterThan($in->occurred_at) && $l->occurred_at->lessThanOrEqualTo($end->addMinutes(self::AFTER_MINUTES)))->last();

            $holiday = $holidays->get($key);
            $weeklyOff = $shift->isWeeklyOff($d);
            $leave = $leaves->first(fn (LeaveRequest $r) => $key >= $r->from_date->toDateString() && $key <= $r->to_date->toDateString());
            $leaveShare = $leave === null ? 0.0 : ($leave->half_day === 'none' ? 1.0 : 0.5);

            if ($in !== null) {
                $row['first_in'] = $in->occurred_at->setTimezone('Asia/Karachi')->format('H:i');
                $late = (int) $start->diffInMinutes($in->occurred_at, false);
                $row['late_minutes'] = $late > $shift->grace_late_minutes ? $late : 0;
            }
            if ($in !== null && $out !== null) {
                $row['last_out'] = $out->occurred_at->setTimezone('Asia/Karachi')->format('H:i');
                $raw = (int) $in->occurred_at->diffInMinutes($out->occurred_at, true);
                // The break is taken out only of a full day; a morning-only half day has no lunch break in it.
                $worked = $raw > $shift->full_day_minutes ? max($shift->full_day_minutes, $raw - $shift->break_minutes) : $raw;
                $row['worked_minutes'] = $worked;
                $early = (int) $out->occurred_at->diffInMinutes($end, false);
                $row['early_minutes'] = $early > $shift->grace_early_minutes ? $early : 0;
                $extra = $worked - $shift->scheduledMinutes();
                $row['overtime_minutes'] = $extra > $shift->overtime_after_minutes ? $extra : 0;
            }

            if ($d->greaterThan($today)) {
                $row['notes'][] = 'future';
            } elseif ($in !== null && $out === null) {
                // Not paid until the missing punch is fixed (manual attendance or review).
                $row['code'] = 'MO';
                $row['notes'][] = 'missing_out';
                $totals['incomplete']++;
            } elseif ($in !== null) {
                $present = $row['worked_minutes'] >= $shift->full_day_minutes ? 1.0 : ($row['worked_minutes'] >= $shift->half_day_minutes ? 0.5 : 0.0);
                if ($present === 0.0) {
                    $row['notes'][] = 'too_short';
                }
                if ($holiday !== null) {
                    $row['notes'][] = 'worked_on_holiday';
                } elseif ($weeklyOff) {
                    $row['notes'][] = 'worked_on_weekly_off';
                }
                if ($leave !== null) {
                    $row['notes'][] = 'present_on_leave';
                    $present = min($present, 1.0 - $leaveShare);
                }
                $row['present'] = $present;
                $this->addLeave($row, $leave, $leaveShare);
                if ($holiday === null && ! $weeklyOff) {
                    $row['absent'] = max(0.0, 1.0 - $present - $row['paid_leave'] - $row['unpaid_leave']);
                }
            } elseif ($holiday !== null) {
                $row['code'] = 'HO';
                $totals['holidays']++;
            } elseif ($weeklyOff) {
                $row['code'] = 'WO';
                $totals['weekly_offs']++;
            } else {
                $this->addLeave($row, $leave, $leaveShare);
                $row['absent'] = 1.0 - $row['paid_leave'] - $row['unpaid_leave'];
            }

            if ($row['code'] === '—' && ! in_array('future', $row['notes'], true)) {
                $row['code'] = self::code($row);
            }
            if (! in_array('future', $row['notes'], true)) {
                foreach (['present', 'paid_leave', 'unpaid_leave', 'absent'] as $k) {
                    $totals[$k] += $row[$k];
                }
                foreach (['late_minutes', 'early_minutes', 'overtime_minutes', 'worked_minutes'] as $k) {
                    $totals[$k] += $row[$k];
                }
            }
            $days[] = $row;
        }

        return ['days' => $days, 'totals' => $totals];
    }

    /** @param  array<string, mixed>  $row */
    private function addLeave(array &$row, ?LeaveRequest $leave, float $share): void
    {
        if ($leave === null) {
            return;
        }
        $row['leave_type'] = $leave->leaveType->code;
        $row[$leave->leaveType->paid ? 'paid_leave' : 'unpaid_leave'] = $share;
    }

    /** @param  array<string, mixed>  $row */
    private static function code(array $row): string
    {
        $leave = $row['paid_leave'] + $row['unpaid_leave'];

        return match (true) {
            $row['present'] >= 1.0 => 'P',
            $row['present'] === 0.5 && $leave === 0.5 => '½P ½L',
            $row['present'] === 0.5 => '½P',
            $leave >= 1.0 => 'L',
            $leave === 0.5 => '½L',
            default => 'A',
        };
    }

    /** Used when an employee has no shift yet: 09:00–18:00, 1 h break, Sunday off. */
    public static function defaultShift(): Shift
    {
        return new Shift([
            'code' => 'DEFAULT', 'name' => 'Default', 'start_time' => '09:00', 'end_time' => '18:00', 'break_minutes' => 60,
            'grace_late_minutes' => 10, 'grace_early_minutes' => 10, 'full_day_minutes' => 420, 'half_day_minutes' => 210,
            'overtime_after_minutes' => 30, 'weekly_offs' => [7],
        ]);
    }
}
