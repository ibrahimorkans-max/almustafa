<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

/**
 * Bill amounts from frozen slip and sauda figures. Pure; integer paise, half-up rounding.
 *
 *   gross     = payable kg × rate per maund / 40
 *   rate cut  = payable kg × cut per maund / 40        (quality, from the lab decision)
 *   brokerage = payable kg × brokerage per maund / 40 (paid by the mill to the broker)
 *   net       = gross − rate cut, rounded to the rupee when $roundToRupee (difference = round-off)
 */
final class PurchaseBillCalculator
{
    /**
     * @return array{gross_paise: int, rate_cut_paise: int, brokerage_paise: int, round_off_paise: int, net_paise: int}
     */
    public function calculate(string $payableKg, int $ratePaisePerMaund, int $rateCutPaisePerMaund, int $brokeragePaisePerMaund, bool $roundToRupee): array
    {
        $gross = self::perMaund($payableKg, $ratePaisePerMaund);
        $cut = self::perMaund($payableKg, $rateCutPaisePerMaund);
        $brokerage = self::perMaund($payableKg, $brokeragePaisePerMaund);
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

    public static function perMaund(string $kg, int $paisePerMaund): int
    {
        $exact = bcdiv(bcmul($kg, (string) $paisePerMaund, 3), '40', 3);

        return (int) bcadd($exact, '0.5', 0);
    }
}
