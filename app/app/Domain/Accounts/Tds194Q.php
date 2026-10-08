<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Documents\DocumentNumbers;
use App\Models\Accounts\PurchaseBill;
use App\Models\Masters\Party;

/**
 * TDS on purchase of goods (Income-tax s. 194Q): when enabled, the buyer deducts 0.1 % of the
 * purchase value from a seller above ₹50 lakh in the financial year (5 % without the seller's PAN,
 * s. 206AA). Only the part of this bill above the threshold is taxed. Pure apart from reading the
 * seller's earlier posted bills of the same year.
 */
final class Tds194Q
{
    /** @return array{tds_paise: int, tds_rate_bp: int, tds_base_paise: int} */
    public function forBill(PurchaseBill $bill, Party $seller): array
    {
        $none = ['tds_paise' => 0, 'tds_rate_bp' => 0, 'tds_base_paise' => 0];
        if (! config('ricemill.gst.tds_194q_enabled') || $seller->tds_194q_exempt) {
            return $none;
        }
        $value = $bill->gross_paise - $bill->rate_cut_paise;
        $fy = DocumentNumbers::financialYear($bill->bill_date);
        $earlier = (int) PurchaseBill::query()->where('party_id', $seller->id)->where('status', PurchaseBill::POSTED)
            ->whereKeyNot($bill->id)->get(['bill_date', 'gross_paise', 'rate_cut_paise'])
            ->filter(fn (PurchaseBill $b) => DocumentNumbers::financialYear($b->bill_date) === $fy)
            ->sum(fn (PurchaseBill $b) => $b->gross_paise - $b->rate_cut_paise);

        $threshold = (int) config('ricemill.gst.tds_194q_threshold_paise');
        $base = max(0, $earlier + $value - $threshold) - max(0, $earlier - $threshold);
        if ($base <= 0) {
            return $none;
        }
        $rate = blank($seller->pan) ? (int) config('ricemill.gst.tds_194q_no_pan_rate_bp') : (int) config('ricemill.gst.tds_194q_rate_bp');

        return ['tds_paise' => intdiv($base * $rate * 2 + 10000, 20000), 'tds_rate_bp' => $rate, 'tds_base_paise' => $base];
    }
}
