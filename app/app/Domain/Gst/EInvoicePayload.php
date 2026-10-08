<?php

declare(strict_types=1);

namespace App\Domain\Gst;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;

/**
 * NIC e-invoice (schema 1.1) and e-way bill payloads, built only from the posted invoice and its
 * frozen snapshot. Amounts in rupees with two decimals, as the schema requires.
 */
final class EInvoicePayload
{
    /** @return array<string, mixed> */
    public static function invoice(SalesBill $bill, Entity $seller, Party $buyer): array
    {
        if ($bill->status !== SalesBill::POSTED || $bill->number === null) {
            throw new BusinessRuleViolation('Only a posted invoice can be reported to the e-invoice system.');
        }
        if ($bill->buyer_gstin === null) {
            throw new BusinessRuleViolation('E-invoices are for registered buyers (B2B); this buyer has no GSTIN.');
        }
        foreach (['gstin' => 'GSTIN', 'state_code' => 'state code', 'pincode' => 'PIN code', 'city' => 'city'] as $field => $label) {
            if (blank($seller->getAttribute($field))) {
                throw new BusinessRuleViolation("Set the mill's {$label} (Entities) before e-invoicing.");
            }
        }
        $rs = fn (int $p) => round($p / 100, 2);
        $qty = (float) $bill->quantity_kg;
        $unitPrice = round($bill->rate_paise_per_quintal / 100 / 100, 2); // ₹ per kg

        return [
            'Version' => '1.1',
            'TranDtls' => ['TaxSch' => 'GST', 'SupTyp' => 'B2B', 'RegRev' => 'N', 'IgstOnIntra' => 'N'],
            'DocDtls' => ['Typ' => 'INV', 'No' => $bill->number, 'Dt' => $bill->bill_date->format('d/m/Y')],
            'SellerDtls' => [
                'Gstin' => $seller->gstin, 'LglNm' => $seller->legal_name ?? $seller->name,
                'Addr1' => mb_substr((string) ($seller->address ?: $seller->city), 0, 100), 'Loc' => $seller->city,
                'Pin' => (int) $seller->pincode, 'Stcd' => $seller->state_code,
            ],
            'BuyerDtls' => [
                'Gstin' => $bill->buyer_gstin, 'LglNm' => $buyer->legal_name ?? $buyer->name, 'Pos' => $bill->place_of_supply,
                'Addr1' => mb_substr((string) ($buyer->address ?: $buyer->city ?: 'NA'), 0, 100), 'Loc' => $buyer->city ?: 'NA',
                'Pin' => (int) ($buyer->pincode ?: 0), 'Stcd' => $buyer->state_code ?? $bill->place_of_supply,
            ],
            'ItemList' => [[
                'SlNo' => '1', 'PrdDesc' => (string) ($bill->snapshot['product']['name'] ?? ''), 'IsServc' => 'N',
                'HsnCd' => $bill->hsn_code, 'Qty' => $qty, 'Unit' => 'KGS', 'UnitPrice' => $unitPrice,
                'TotAmt' => $rs($bill->taxable_paise), 'AssAmt' => $rs($bill->taxable_paise), 'GstRt' => round($bill->gst_rate_bp / 100, 2),
                'IgstAmt' => $rs($bill->igst_paise), 'CgstAmt' => $rs($bill->cgst_paise), 'SgstAmt' => $rs($bill->sgst_paise),
                'TotItemVal' => $rs($bill->taxable_paise + $bill->igst_paise + $bill->cgst_paise + $bill->sgst_paise),
            ]],
            'ValDtls' => [
                'AssVal' => $rs($bill->taxable_paise), 'CgstVal' => $rs($bill->cgst_paise), 'SgstVal' => $rs($bill->sgst_paise),
                'IgstVal' => $rs($bill->igst_paise), 'RndOffAmt' => $rs($bill->round_off_paise), 'TotInvVal' => $rs($bill->total_paise),
            ],
        ];
    }

    /** @return array<string, mixed> e-way bill Part A + B for an invoice that already has an IRN */
    public static function ewayBill(SalesBill $bill, ?GateEntry $entry, int $distanceKm): array
    {
        if ($bill->irn === null) {
            throw new BusinessRuleViolation('Generate the e-invoice (IRN) first; the e-way bill is created from it.');
        }
        if ($distanceKm < 1 || $distanceKm > 4000) {
            throw new BusinessRuleViolation('Distance must be between 1 and 4000 km.');
        }
        $vehicle = $entry->registration_no ?? (string) ($bill->snapshot['registration_no'] ?? '');
        if ($vehicle === '') {
            throw new BusinessRuleViolation('The vehicle number is needed for Part B.');
        }

        return ['Irn' => $bill->irn, 'Distance' => $distanceKm, 'TransMode' => '1', 'VehNo' => $vehicle, 'VehType' => 'R'];
    }
}
