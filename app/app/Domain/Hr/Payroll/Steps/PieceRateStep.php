<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll\Steps;

use App\Domain\Hr\Payroll\PayContext;
use App\Domain\Hr\Payroll\PayStep;
use App\Models\Hr\PieceWorkEntry;
use App\Models\Hr\SalaryComponent;

/**
 * Piece-rate wages: the month's piece-work entries × the rate per unit in the salary structure in
 * force on the day the work was done. Units without a rate are reported, never guessed.
 */
final class PieceRateStep implements PayStep
{
    public function apply(PayContext $c): void
    {
        $entries = PieceWorkEntry::query()->where('employee_id', $c->employee->id)
            ->whereBetween('work_date', [$c->monthStart->toDateString(), $c->monthEnd()->toDateString()])
            ->orderBy('work_date')->get();
        if ($entries->isEmpty()) {
            return;
        }
        $sum = [];
        $qty = [];
        $names = [];
        $rates = [];
        $units = [];
        $unpriced = [];
        foreach ($entries as $e) {
            $day = $e->work_date->toDateString();
            $line = $c->structureOn($day)?->lines->first(fn ($l) => $l->unit === $e->unit && $l->component->kind === SalaryComponent::EARNING);
            if ($line === null) {
                $unpriced[$e->unit] = bcadd($unpriced[$e->unit] ?? '0', (string) $e->quantity, 3);

                continue;
            }
            $code = $line->component->code;
            $names[$code] = $line->component->name;
            $rates[$code] = $line->amount_paise;
            $qty[$code] = bcadd($qty[$code] ?? '0', (string) $e->quantity, 3);
            $units[$code] = $e->unit;
            $sum[$code] = bcadd($sum[$code] ?? '0', bcmul((string) $e->quantity, (string) $line->amount_paise, 6), 6);
        }
        foreach ($sum as $code => $value) {
            [$q, $unit] = [$qty[$code], $units[$code]];
            $c->earn($code, $names[$code], PayContext::round($value),
                sprintf('%s %s × %s', rtrim(rtrim($q, '0'), '.'), $unit, PayContext::money($rates[$code])), ['component' => true, 'unit' => $unit, 'quantity' => $q]);
        }
        foreach ($unpriced as $unit => $q) {
            $c->warnings[] = "{$q} {$unit} of piece work have no rate in the salary structure and are not paid.";
        }
        $c->info['pay_basis'] ??= 'piece_rate';
    }
}
