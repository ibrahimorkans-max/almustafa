<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Models\Alert;
use App\Models\Documents\DocumentBatch;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollRun;
use App\Support\Values\Money;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;

/**
 * Payroll and delivery rules, run with the nightly anomaly scan (one entity at a time). Like the
 * other rules, each alert states the numbers and why it fired, and is raised once per finding.
 * * abnormal_overtime: an employee's overtime in the latest finalized payroll is above the monthly
 *   limit, or more than twice their usual (median of the previous six months, with three or more);
 * * payroll_variance: the finalized payroll's gross pay moved more than the set share from the month before;
 * * minimum_wage_shortfall: employees paid below the minimum wage in a finalized payroll;
 * * failed_delivery: a bulk document run that failed, or left records it could not produce.
 */
final class HrAnomalies
{
    public function __construct(private readonly AlertService $alerts) {}

    /** @return array<string, int> */
    public function scan(CarbonInterface $since): array
    {
        $raised = ['abnormal_overtime' => 0, 'payroll_variance' => 0, 'minimum_wage_shortfall' => 0, 'failed_delivery' => 0];
        $run = PayrollRun::query()->where('status', PayrollRun::FINALIZED)->orderByDesc('month')->first();
        if ($run !== null) {
            $items = PayrollItem::query()->where('payroll_run_id', $run->id)->where('calculation_no', $run->calculation_no)->get();
            $raised['abnormal_overtime'] = $this->overtime($run, $items->all());
            $raised['payroll_variance'] = $this->variance($run, (int) $items->sum('gross_paise'));
            $raised['minimum_wage_shortfall'] = $this->minimumWage($run, $items->all());
        }
        $raised['failed_delivery'] = $this->failedDeliveries($since);

        return $raised;
    }

    /** @param  list<PayrollItem>  $items */
    private function overtime(PayrollRun $run, array $items): int
    {
        $limit = (int) config('ricemill.anomalies.overtime_hours_month', 60) * 60;
        $previous = PayrollRun::query()->where('status', PayrollRun::FINALIZED)->where('month', '<', $run->month)->orderByDesc('month')->limit(6)->get();
        $n = 0;
        foreach ($items as $item) {
            $minutes = (int) ($item->result['info']['overtime_minutes'] ?? 0);
            if ($minutes <= 0) {
                continue;
            }
            $history = [];
            foreach ($previous as $p) {
                $old = PayrollItem::query()->where('payroll_run_id', $p->id)->where('calculation_no', $p->calculation_no)->where('employee_id', $item->employee_id)->first();
                if ($old !== null) {
                    $history[] = (int) ($old->result['info']['overtime_minutes'] ?? 0);
                }
            }
            sort($history);
            $usual = count($history) >= 3 ? $history[intdiv(count($history), 2)] : null;
            $why = $minutes > $limit ? sprintf('above the limit of %d hours a month', $limit / 60)
                : ($usual !== null && $usual > 0 && $minutes > 2 * $usual ? sprintf('more than twice the usual %s hours', round($usual / 60, 1)) : null);
            if ($why === null) {
                continue;
            }
            $who = ($item->employee['code'] ?? '').' '.($item->employee['name'] ?? '');
            $n += (int) $this->raise('abnormal_overtime', "{$run->id}:{$item->employee_id}", "Overtime {$run->month}: {$who}",
                sprintf('%s worked %s hours of overtime in %s, %s. Check the punches and who approved the extra hours.', trim($who), round($minutes / 60, 1), $run->month, $why),
                ['run' => $run->number, 'month' => $run->month, 'employee_id' => $item->employee_id, 'overtime_minutes' => $minutes, 'usual_minutes' => $usual], 'hr.salary.view');
        }

        return $n;
    }

    private function variance(PayrollRun $run, int $gross): int
    {
        $before = PayrollRun::query()->where('status', PayrollRun::FINALIZED)->where('month', '<', $run->month)->orderByDesc('month')->first();
        if ($before === null) {
            return 0;
        }
        $old = (int) PayrollItem::query()->where('payroll_run_id', $before->id)->where('calculation_no', $before->calculation_no)->sum('gross_paise');
        $limitBp = (int) config('ricemill.anomalies.payroll_variance_bp', 1500);
        if ($old <= 0 || abs($gross - $old) * 10000 <= $old * $limitBp) {
            return 0;
        }
        $pct = round(($gross - $old) * 100 / $old, 1);

        return (int) $this->raise('payroll_variance', $run->id, "Payroll {$run->month} ".($pct > 0 ? 'up' : 'down')." {$pct}% on {$before->month}",
            sprintf('Gross pay for %s is Rs %s against Rs %s for %s (%+.1f%%; alert above %s%%). Check new joiners, leavers, overtime and pay changes.',
                $run->month, Money::paise($gross)->grouped(), Money::paise($old)->grouped(), $before->month, $pct, $limitBp / 100),
            ['run' => $run->number, 'month' => $run->month, 'gross_paise' => $gross, 'previous_gross_paise' => $old], 'hr.salary.view');
    }

    /** @param  list<PayrollItem>  $items */
    private function minimumWage(PayrollRun $run, array $items): int
    {
        $below = array_values(array_filter($items, fn (PayrollItem $i) => ! empty($i->result['info']['below_minimum_wage'])));
        if ($below === []) {
            return 0;
        }
        $names = array_map(fn (PayrollItem $i) => trim(($i->employee['code'] ?? '').' '.($i->employee['name'] ?? '')), $below);

        return (int) $this->raise('minimum_wage_shortfall', $run->id, count($below)." employee(s) below the minimum wage in {$run->month}",
            'Paid below the minimum wage set in Payroll settings: '.implode(', ', array_slice($names, 0, 20)).(count($names) > 20 ? ' …' : '')
                .'. Check their pay structure and days; the law requires at least the notified minimum for a full month.',
            ['run' => $run->number, 'month' => $run->month, 'employees' => array_map(fn (PayrollItem $i) => $i->employee_id, $below)], 'hr.salary.view');
    }

    private function failedDeliveries(CarbonInterface $since): int
    {
        $n = 0;
        $batches = DocumentBatch::query()->where('updated_at', '>=', $since)
            ->where(fn ($q) => $q->where('status', DocumentBatch::FAILED)->orWhereNotNull('failed'))->get();
        foreach ($batches as $b) {
            $failed = is_array($b->failed) ? count($b->failed) : 0;
            if ($b->status !== DocumentBatch::FAILED && $failed === 0) {
                continue;
            }
            $n += (int) $this->raise('failed_delivery', $b->id, $b->status === DocumentBatch::FAILED ? 'Bulk documents failed' : "Bulk documents: {$failed} record(s) not produced",
                $b->status === DocumentBatch::FAILED
                    ? 'A bulk document run stopped: '.mb_substr((string) $b->error, 0, 300).'. Open HR → Bulk documents and retry.'
                    : "{$failed} record(s) could not be produced. Open HR → Bulk documents to see why and retry the failed ones.",
                ['batch_id' => $b->id, 'failed' => $failed], 'hr.documents.generate');
        }

        return $n;
    }

    /** @param  array<string, mixed>  $data */
    private function raise(string $rule, string $key, string $title, string $body, array $data, string $permission): bool
    {
        $subjectId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'hr-anomaly:'.$rule.':'.$key)->toString();
        if (Alert::query()->where('type', $rule)->where('subject_id', $subjectId)->exists()) {
            return false;
        }
        $alert = $this->alerts->raise($rule, 'warning', $title, $body, null, $data + ['rule' => $rule], $permission);
        $alert->forceFill(['subject_type' => 'anomaly', 'subject_id' => $subjectId])->save();

        return true;
    }
}
