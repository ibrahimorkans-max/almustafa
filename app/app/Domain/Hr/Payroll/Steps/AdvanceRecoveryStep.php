<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;
use App\Models\Hr\Advance;

/**
 * Recovers the month's instalment of every disbursed advance or loan whose recovery has started,
 * never more than the balance, oldest first. Recovery never makes net pay negative: what does not
 * fit stays on the balance for next month (and the payroll shows a warning).
 */
final class AdvanceRecoveryStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        $advances = Advance::query()->where('employee_id', $c->employee->id)->where('status', Advance::DISBURSED)
            ->where('start_month', '<=', $c->month())->where('disbursed_at', '<=', $c->monthEnd()->endOfDay()->utc())
            ->orderBy('disbursed_at')->get();
        $available = $c->gross() - $c->totalDeductions();
        foreach ($advances as $advance) {
            $due = min($advance->installment_paise, $advance->balancePaise());
            if ($due <= 0) {
                continue;
            }
            $take = max(0, min($due, $available));
            if ($take < $due) {
                $c->warnings[] = sprintf('Advance %s: only %s of the %s instalment fits in this month\'s pay; the rest stays on the balance.',
                    $advance->number, PayContext::money($take), PayContext::money($due));
            }
            $available -= $take;
            $c->deduct('ADV', ($advance->kind === 'loan' ? 'Loan recovery' : 'Advance recovery').' '.$advance->number, $take,
                sprintf('instalment %s, balance before %s', PayContext::money($advance->installment_paise), PayContext::money($advance->balancePaise())),
                ['advance_id' => $advance->id]);
        }
    }
}
