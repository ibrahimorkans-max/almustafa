<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;
use App\Models\Hr\PayAdjustment;

/**
 * Approved one-off amounts of the month: bonuses and other earnings are added, other deductions
 * taken. Fines together may not exceed the cap (Payment of Wages Act; setting, default 3 % of the
 * month's wages): what is above the cap is not deducted, and the payroll says so. Requests not yet
 * approved are listed as warnings, never counted.
 */
final class AdjustmentsStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        $all = PayAdjustment::query()->where('employee_id', $c->employee->id)->where('month', $c->month())
            ->whereIn('status', [PayAdjustment::APPROVED, PayAdjustment::REQUESTED])->orderBy('created_at')->get();
        foreach ($all->where('status', PayAdjustment::REQUESTED) as $pending) {
            $c->warnings[] = sprintf('%s of %s (%s) is waiting for approval and is not included.', $pending->name, PayContext::money($pending->amount_paise), $pending->reason);
        }
        $approved = $all->where('status', PayAdjustment::APPROVED);
        foreach ($approved->filter(fn (PayAdjustment $a) => $a->isEarning()) as $a) {
            $c->earn($a->code, $a->name, $a->amount_paise, $a->reason, ['adjustment_id' => $a->id, 'type' => $a->type]);
        }
        foreach ($approved->where('type', 'deduction') as $a) {
            $c->deduct($a->code, $a->name, $a->amount_paise, $a->reason, ['adjustment_id' => $a->id]);
        }
        $cap = PayContext::round(bcdiv((string) ($c->gross() * (int) $c->setting('fine_cap_bp')), '10000', 6));
        $room = $cap;
        foreach ($approved->where('type', 'fine') as $a) {
            $take = min($a->amount_paise, max(0, $room));
            $room -= $take;
            if ($take < $a->amount_paise) {
                $c->warnings[] = sprintf('Fine %s cut to %s: fines may not exceed %s of wages (%s).', PayContext::money($a->amount_paise),
                    PayContext::money($take), rtrim(rtrim(number_format((int) $c->setting('fine_cap_bp') / 100, 2), '0'), '.').'%', PayContext::money($cap));
            }
            $c->deduct($a->code, $a->name, $take, $a->reason, ['adjustment_id' => $a->id]);
        }
    }
}
