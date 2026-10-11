<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\PayrollRun;
use Carbon\CarbonInterface;

/**
 * Once a month's payroll has locked its attendance, the attendance of that month may no longer be
 * changed (reviews, manual punches, leave decisions, imports): the figures being paid are frozen.
 * A correction goes into a later month as arrears / a deduction, or the run is unlocked (before
 * approval, with a reason).
 */
final class PayrollLocks
{
    public static function isLocked(CarbonInterface $day): bool
    {
        $month = $day->copy()->setTimezone('Asia/Karachi')->format('Y-m');

        return PayrollRun::query()->where('month', $month)->where('status', '!=', PayrollRun::DRAFT)->exists();
    }

    public static function assertOpen(CarbonInterface $day, string $what = 'Attendance'): void
    {
        if (self::isLocked($day)) {
            $month = $day->copy()->setTimezone('Asia/Karachi')->format('M Y');
            throw new BusinessRuleViolation("{$what} for {$month} is locked by that month's payroll. Correct it in a later month (arrears or a deduction), or ask payroll to unlock the run before it is approved.");
        }
    }
}
