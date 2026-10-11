<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Accounts\PurchaseBillCalculator;
use App\Models\Entity;
use App\Models\Masters\Party;
use App\Models\Masters\Product;

/**
 * Sales tax on a sales invoice line (Sales Tax Act 1990). Pure; integer paisa, half-up rounding.
 *
 *   value excluding tax = net kg × rate per maund / 40
 *   sales tax           = value × the product's rate (0 for exempt / zero-rated goods)
 *   further tax         = value × further-tax rate, only on supplies to a buyer without an STRN (s. 3(1A))
 *
 * The invoice total is rounded to the rupee; the difference is the round-off. A mill without an
 * STRN issues commercial invoices: both rates are 0.
 */
final class SalesTaxCalculator
{
    /**
     * @return array{taxable_paise: int, sales_tax_paise: int, further_tax_paise: int, round_off_paise: int, total_paise: int}
     */
    public function calculate(string $quantityKg, int $ratePaisePerMaund, int $taxRateBp, int $furtherTaxRateBp = 0, bool $roundToRupee = true): array
    {
        $taxable = PurchaseBillCalculator::perMaund($quantityKg, $ratePaisePerMaund);
        $tax = self::percentOf($taxable, $taxRateBp);
        $further = self::percentOf($taxable, $furtherTaxRateBp);
        $raw = $taxable + $tax + $further;
        $total = $roundToRupee ? intdiv($raw + 50, 100) * 100 : $raw;

        return [
            'taxable_paise' => $taxable, 'sales_tax_paise' => $tax, 'further_tax_paise' => $further,
            'round_off_paise' => $total - $raw, 'total_paise' => $total,
        ];
    }

    /**
     * Which invoice and which rates apply: a Sales Tax Invoice only when the mill has an STRN; the product's
     * rate (none when exempt); further tax only on a taxable supply to a buyer without an STRN.
     *
     * @return array{invoice_type: string, tax_rate_bp: int, tax_exempt: bool, further_tax_rate_bp: int, seller_strn: string|null}
     */
    public static function terms(?Entity $mill, Product $product, Party $buyer): array
    {
        $strn = $mill?->strn;
        if ($strn === null || $strn === '') {
            return ['invoice_type' => 'commercial', 'tax_rate_bp' => 0, 'tax_exempt' => false, 'further_tax_rate_bp' => 0, 'seller_strn' => null];
        }
        $exempt = (bool) ($product->taxRate->exempt ?? false);
        $rate = $exempt ? 0 : (int) ($product->taxRate->sales_tax_rate_bp ?? 0);
        $further = $rate > 0 && ! $buyer->isSalesTaxRegistered() && config('ricemill.tax.further_tax_enabled')
            ? (int) config('ricemill.tax.further_tax_rate_bp') : 0;

        return ['invoice_type' => 'sales_tax', 'tax_rate_bp' => $rate, 'tax_exempt' => $exempt, 'further_tax_rate_bp' => $further, 'seller_strn' => $strn];
    }

    /** amount × bp / 10000, rounded half-up. */
    public static function percentOf(int $amount, int $bp): int
    {
        return intdiv($amount * $bp * 2 + 10000, 20000);
    }
}
