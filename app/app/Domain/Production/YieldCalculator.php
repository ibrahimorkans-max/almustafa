<?php

declare(strict_types=1);

namespace App\Domain\Production;

use App\Support\Values\Weight;

/**
 * Input-to-output yield of a milling batch. Pure: same inputs, same snapshot.
 * Shares are basis points of paddy input (6700 = 67.00 %).
 */
final class YieldCalculator
{
    /**
     * @param  array<string, string>  $outputs  product_id => kg
     * @param  array<string, array{expected_bp: int, tolerance_bp: int}>  $standards  output product_id => standard
     * @return array{input_kg: string, output_kg: string, unaccounted_kg: string, unaccounted_bp: int, outturn: array<string, array{kg: string, bp: int, expected_bp: int|null, tolerance_bp: int|null, drift_bp: int|null, out_of_range: bool}>}
     */
    public function calculate(string $inputKg, array $outputs, array $standards): array
    {
        $input = Weight::kg($inputKg);
        $total = Weight::zero();
        $outturn = [];

        foreach ($outputs as $productId => $kg) {
            $total = $total->plus(Weight::kg($kg));
        }

        foreach (array_unique([...array_keys($outputs), ...array_keys($standards)]) as $productId) {
            $kg = Weight::kg($outputs[$productId] ?? '0');
            $bp = self::bp($kg, $input);
            $standard = $standards[$productId] ?? null;
            $drift = $standard !== null ? $bp - $standard['expected_bp'] : null;
            $outturn[$productId] = [
                'kg' => $kg->kg,
                'bp' => $bp,
                'expected_bp' => $standard['expected_bp'] ?? null,
                'tolerance_bp' => $standard['tolerance_bp'] ?? null,
                'drift_bp' => $drift,
                'out_of_range' => $standard !== null && abs((int) $drift) > $standard['tolerance_bp'],
            ];
        }

        $unaccounted = $input->minus($total);

        return [
            'input_kg' => $input->kg,
            'output_kg' => $total->kg,
            'unaccounted_kg' => $unaccounted->kg,
            'unaccounted_bp' => self::bp($unaccounted, $input),
            'outturn' => $outturn,
        ];
    }

    /** Share in basis points, rounded half away from zero. */
    private static function bp(Weight $part, Weight $whole): int
    {
        if (bccomp($whole->kg, '0', 3) === 0) {
            return 0;
        }
        $raw = bcdiv(bcmul($part->kg, '10000', 6), $whole->kg, 6);

        return (int) (str_starts_with($raw, '-') ? bcsub($raw, '0.5', 0) : bcadd($raw, '0.5', 0));
    }
}
