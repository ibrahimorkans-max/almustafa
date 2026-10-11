<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;
use App\Models\Hr\SalaryComponent;
use Carbon\CarbonImmutable;

/**
 * The structure's earnings, day by day, so a salary change or a joining / exit date in the middle of
 * the month is applied exactly:
 *
 *   monthly: Σ over the days on the rolls of (monthly amount in force that day × paid share of the day)
 *            ÷ calendar days of the month. Holidays and weekly offs are paid; absence, unpaid leave
 *            and days with a missing punch (not yet corrected) are loss of pay.
 *   daily:   Σ (day rate in force × days present + paid leave [+ public holidays, by setting]).
 *
 * Piece-rate earnings come from PieceRateStep.
 */
final class BasePayStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        $basis = $c->payBasis();
        if ($basis === null) {
            $c->warnings[] = 'No salary structure: nothing to pay.';

            return;
        }
        if ($basis === 'piece_rate') {
            return;
        }

        $halves = [];      // code => Σ amount × paid half-days
        $names = [];
        $paidHalves = 0;
        $lopHalves = 0;
        $noStructure = 0;
        $future = 0;
        $today = CarbonImmutable::now('Asia/Karachi')->toDateString();
        $paidHolidays = (bool) $c->setting('daily_paid_holidays');

        foreach ($c->days as $date => $d) {
            if (in_array('not_employed', $d['notes'], true)) {
                continue;
            }
            $structure = $c->structureOn($date);
            if ($structure === null) {
                $noStructure++;

                continue;
            }
            if ($date > $today) {
                $future++;
            }
            $missingOut = $d['code'] === 'MO';
            $holidayOrOff = in_array($d['code'], ['HO', 'WO'], true) || in_array('worked_on_holiday', $d['notes'], true) || in_array('worked_on_weekly_off', $d['notes'], true);
            if ($structure->pay_basis === 'monthly') {
                $share = $date > $today ? 1.0 : ($missingOut ? 0.0 : 1.0 - (float) $d['absent'] - (float) $d['unpaid_leave']);
            } else {
                $isPublicHoliday = $d['code'] === 'HO' || in_array('worked_on_holiday', $d['notes'], true);
                $share = (float) $d['present'] + (float) $d['paid_leave'] + ($paidHolidays && $isPublicHoliday && (float) $d['present'] === 0.0 ? 1.0 : 0.0);
                $share = min(1.0, $share);
            }
            $h = (int) round(max(0.0, $share) * 2);
            $paidHalves += $h;
            if ($structure->pay_basis === 'monthly' || ! $holidayOrOff) {
                $lopHalves += 2 - $h;
            }
            foreach ($structure->lines as $line) {
                if ($line->component->kind !== SalaryComponent::EARNING) {
                    continue;
                }
                $code = $line->component->code;
                $names[$code] = $line->component->name;
                $halves[$code] = bcadd($halves[$code] ?? '0', (string) ($line->amount_paise * $h));
            }
        }

        $monthly = $basis === 'monthly';
        foreach ($halves as $code => $sum) {
            $divisor = $monthly ? 2 * $c->daysInMonth : 2;
            $amount = PayContext::round(bcdiv($sum, (string) $divisor, 6));
            $basisText = $monthly
                ? sprintf('%s paid days of %d (monthly rate %s)', self::days($paidHalves), $c->daysInMonth, PayContext::money($c->amountOn($c->monthEnd()->toDateString(), $code)))
                : sprintf('%s days × %s per day', self::days($paidHalves), PayContext::money($c->amountOn($c->monthEnd()->toDateString(), $code)));
            $c->earn($code, $names[$code], $amount, $basisText, ['component' => true]);
        }

        $c->info['pay_basis'] = $basis;
        $c->info['paid_days'] = self::days($paidHalves);
        $c->info['lop_days'] = self::days($lopHalves);
        $c->info['calendar_days'] = $c->daysInMonth;
        $c->info['employed_days'] = $c->employedDays();
        if ($noStructure > 0) {
            $c->warnings[] = "{$noStructure} day(s) before the first salary structure are not paid.";
        }
        if ($future > 0) {
            $c->warnings[] = "{$future} day(s) of the month are still to come and are counted as paid.";
        }
        $incomplete = (int) ($c->totals['incomplete'] ?? 0);
        if ($incomplete > 0) {
            $c->warnings[] = "{$incomplete} day(s) with a missing punch are unpaid until corrected.";
        }
    }

    private static function days(int $halves): string
    {
        return rtrim(rtrim(number_format($halves / 2, 1, '.', ''), '0'), '.');
    }
}
