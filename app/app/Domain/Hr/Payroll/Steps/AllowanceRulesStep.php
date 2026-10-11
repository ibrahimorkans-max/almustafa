<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;
use App\Models\Hr\AllowanceRule;

/**
 * Allowances paid by rule, for the employees a rule applies to:
 *   fixed      amount per month (prorated by paid days ÷ calendar days when the rule says so)
 *   pct_basic  percentage of the Basic actually earned this month
 *   per_day    amount × days present (half days count half)
 *   attendance fixed amount only if absences (incl. unpaid leave) and late days stay within the limits
 */
final class AllowanceRulesStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        if ($c->payBasis() === null) {
            return;
        }
        $rules = AllowanceRule::query()->where('status', 'active')
            ->where('effective_from', '<=', $c->monthEnd()->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $c->monthStart->toDateString()))
            ->orderBy('code')->get();
        $paidDays = (float) ($c->info['paid_days'] ?? 0);
        $present = (float) ($c->totals['present'] ?? 0);
        $absent = (float) ($c->totals['absent'] ?? 0) + (float) ($c->totals['unpaid_leave'] ?? 0);
        $lateDays = count(array_filter($c->days, fn (array $d) => (int) ($d['late_minutes'] ?? 0) > 0));

        foreach ($rules as $rule) {
            if (! $rule->appliesTo($c->employee)) {
                continue;
            }
            [$amount, $basis] = match ($rule->method) {
                'fixed' => $rule->prorate
                    ? [PayContext::round(bcdiv((string) ($rule->amount_paise * (int) round($paidDays * 2)), (string) (2 * $c->daysInMonth), 6)),
                        sprintf('%s × %s paid days ÷ %d', PayContext::money($rule->amount_paise), self::n($paidDays), $c->daysInMonth)]
                    : [$rule->amount_paise, 'fixed per month'],
                'pct_basic' => [PayContext::round(bcdiv((string) ($c->earned('BASIC') * $rule->rate_bp), '10000', 6)),
                    sprintf('%s of Basic earned %s', rtrim(rtrim(number_format($rule->rate_bp / 100, 2), '0'), '.').'%', PayContext::money($c->earned('BASIC')))],
                'per_day' => [PayContext::round(bcdiv((string) ($rule->amount_paise * (int) round($present * 2)), '2', 6)),
                    sprintf('%s days present × %s', self::n($present), PayContext::money($rule->amount_paise))],
                'attendance' => ($rule->max_absent_days === null || $absent <= $rule->max_absent_days) && ($rule->max_late_days === null || $lateDays <= $rule->max_late_days)
                    ? [$rule->amount_paise, sprintf('%s absent, %d late: within the limits', self::n($absent), $lateDays)]
                    : [0, ''],
                default => [0, ''],
            };
            $c->earn($rule->code, $rule->name, $amount, $basis, ['rule_id' => $rule->id]);
        }
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') ?: '0';
    }
}
