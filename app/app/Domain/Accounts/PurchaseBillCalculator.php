<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

/**
 * Bill amounts from frozen slip and sauda figures. Pure; integer paise, half-up rounding.
 *
 *   gross     = payable kg × rate per quintal / 100
 *   rate cut  = payable kg × cut per quintal / 100       (quality, from the lab decision)
 *   brokerage = payable kg × brokerage per quintal / 100 (paid by the mill to the broker)
 *   net       = gross − rate cut, rounded to the rupee when $roundToRupee (difference = round-off)
 */
final class PurchaseBillCalculator
{
    /**
     * @return array{gross_paise: int, rate_cut_paise: int, brokerage_paise: int, round_off_paise: int, net_paise: int}
     */
    public function calculate(string $payableKg, int $ratePaisePerQuintal, int $rateCutPaisePerQuintal, int $brokeragePaisePerQuintal, bool $roundToRupee): array
    {
        $gross = self::perQuintal($payableKg, $ratePaisePerQuintal);
        $cut = self::perQuintal($payableKg, $rateCutPaisePerQuintal);
        $brokerage = self::perQuintal($payableKg, $brokeragePaisePerQuintal);
        $raw = $gross - $cut;
        $net = $roundToRupee ? intdiv($raw + 50, 100) * 100 : $raw;

        return [
            'gross_paise' => $gross,
            'rate_cut_paise' => $cut,
            'brokerage_paise' => $brokerage,
            'round_off_paise' => $net - $raw,
            'net_paise' => $net,
        ];
    }

    public static function perQuintal(string $kg, int $paisePerQuintal): int
    {
        $exact = bcdiv(bcmul($kg, (string) $paisePerQuintal, 3), '100', 3);

        return (int) bcadd($exact, '0.5', 0);
    }
}
