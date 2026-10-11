<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Documents\DocumentNumbers;
use App\Models\Accounts\PurchaseBill;
use App\Models\Masters\Party;

/**
 * Withholding income tax on purchases of goods (Income Tax Ordinance 2001, s. 153(1)(a)): when enabled,
 * the mill deducts tax from what it pays a supplier, at the filer rate when the supplier is on FBR's
 * Active Taxpayer List and at the non-filer rate otherwise. Nothing is deducted while the supplier's
 * purchases in the tax year (July–June) stay within the threshold; only the part of this bill above it
 * is taxed. Exempt suppliers (e.g. growers selling their own produce, or with an exemption certificate)
 * are skipped. Pure apart from reading the supplier's earlier posted bills of the same year.
 */
final class WithholdingTax
{
    /** @return array{wht_paise: int, wht_rate_bp: int, wht_base_paise: int, wht_filer: bool} */
    public function forBill(PurchaseBill $bill, Party $seller): array
    {
        $none = ['wht_paise' => 0, 'wht_rate_bp' => 0, 'wht_base_paise' => 0, 'wht_filer' => $seller->isFiler()];
        if (! config('ricemill.tax.wht_enabled') || $seller->wht_exempt) {
            return $none;
        }
        $value = $bill->gross_paise - $bill->rate_cut_paise;
        $year = DocumentNumbers::financialYear($bill->bill_date);
        $earlier = (int) PurchaseBill::query()->where('party_id', $seller->id)->where('status', PurchaseBill::POSTED)
            ->whereKeyNot($bill->id)->get(['bill_date', 'gross_paise', 'rate_cut_paise'])
            ->filter(fn (PurchaseBill $b) => DocumentNumbers::financialYear($b->bill_date) === $year)
            ->sum(fn (PurchaseBill $b) => $b->gross_paise - $b->rate_cut_paise);

        $threshold = (int) config('ricemill.tax.wht_threshold_paise');
        $base = max(0, $earlier + $value - $threshold) - max(0, $earlier - $threshold);
        if ($base <= 0) {
            return $none;
        }
        $rate = $seller->isFiler() ? (int) config('ricemill.tax.wht_rate_filer_bp') : (int) config('ricemill.tax.wht_rate_non_filer_bp');

        return ['wht_paise' => intdiv($base * $rate * 2 + 10000, 20000), 'wht_rate_bp' => $rate, 'wht_base_paise' => $base, 'wht_filer' => $seller->isFiler()];
    }
}
