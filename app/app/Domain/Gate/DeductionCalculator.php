<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Models\Gate\DeductionRule;
use App\Support\Values\Weight;

/**
 * Turns lab measurements into weight deductions, rate cuts and an auto-reject flag.
 * Pure function of its inputs, so the result can be stored as an exact snapshot on the
 * lab decision (what was deducted and why) and re-checked later.
 */
final class DeductionCalculator
{
    /**
     * @param  array<string, int>  $measurements  parameter => basis points (moisture => 1850)
     * @param  iterable<DeductionRule>  $rules
     * @return array{deduction_kg: string, rate_cut_paise_per_quintal: int, reject: bool, lines: list<array<string, mixed>>}
     */
    public function calculate(string $netKg, ?int $bagCount, array $measurements, iterable $rules): array
    {
        $net = Weight::kg($netKg);
        $deduction = Weight::zero();
        $rateCut = 0;
        $reject = false;
        $lines = [];

        foreach ($rules as $rule) {
            if ($rule->status !== 'active') {
                continue;
            }

            if ($rule->method === 'per_bag') {
                if ($bagCount === null || $bagCount <= 0) {
                    continue;
                }
                // factor = grams per bag
                $kg = Weight::kg(bcdiv((string) ($rule->factor * $bagCount), '1000', 3));
                $deduction = $deduction->plus($kg);
                $lines[] = ['parameter' => 'bags', 'method' => 'per_bag', 'bags' => $bagCount, 'grams_per_bag' => $rule->factor, 'deduction_kg' => $kg->kg];

                continue;
            }

            $value = $measurements[$rule->parameter] ?? null;
            if ($value === null) {
                continue;
            }

            if ($rule->reject_above_bp !== null && $value > $rule->reject_above_bp) {
                $reject = true;
                $lines[] = ['parameter' => $rule->parameter, 'value_bp' => $value, 'reject_above_bp' => $rule->reject_above_bp, 'result' => 'reject'];

                continue;
            }

            $excess = $value - $rule->free_limit_bp;
            if ($excess <= 0) {
                continue;
            }

            if ($rule->method === 'excess_weight') {
                // net × (excess bp / 10000 %) × (factor / 10000)
                $kg = Weight::kg(bcdiv(bcmul(bcmul($net->kg, (string) $excess, 6), (string) $rule->factor, 6), '100000000', 3));
                $deduction = $deduction->plus($kg);
                $lines[] = ['parameter' => $rule->parameter, 'method' => 'excess_weight', 'value_bp' => $value, 'free_bp' => $rule->free_limit_bp, 'deduction_kg' => $kg->kg];
            } elseif ($rule->method === 'rate_cut') {
                // factor paise/quintal per full 1 % (100 bp) above the free limit
                $points = intdiv($excess, 100);
                $cut = $points * $rule->factor;
                $rateCut += $cut;
                $lines[] = ['parameter' => $rule->parameter, 'method' => 'rate_cut', 'value_bp' => $value, 'free_bp' => $rule->free_limit_bp, 'rate_cut_paise_per_quintal' => $cut];
            }
        }

        if ($deduction->compare($net) > 0) {
            $deduction = $net;
        }

        return [
            'deduction_kg' => $deduction->kg,
            'rate_cut_paise_per_quintal' => $rateCut,
            'reject' => $reject,
            'lines' => $lines,
        ];
    }
}
