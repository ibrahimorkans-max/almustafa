<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Gate\SlipRenderer;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Sales\SalesBill;
use Barryvdh\DomPDF\Facade\Pdf;

/** Printed tax invoice and gate pass, both carrying a verification code over their frozen data. */
final class SalesDocuments
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function invoicePdf(SalesBill $bill): string
    {
        if ($bill->status !== SalesBill::POSTED) {
            throw new BusinessRuleViolation('Only a posted invoice can be printed.');
        }
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($bill->entity_id));

        return Pdf::loadView('sales.invoice', [
            'entity' => $entity, 'bill' => $bill->loadMissing(['party', 'product']),
            'words' => self::rupeesInWords($bill->total_paise),
            'code' => SlipRenderer::verificationCode($bill->id, $bill->only(['number', 'bill_date', 'party_id', 'quantity_kg', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'total_paise'])),
        ])->setPaper('a4')->output();
    }

    public function gatePassPdf(GateEntry $entry): string
    {
        $pass = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'gate_pass_issued')->first()
            ?? throw new BusinessRuleViolation('No gate pass has been issued for this vehicle.');
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($entry->entity_id));

        return Pdf::loadView('sales.gate-pass', [
            'entity' => $entity, 'entry' => $entry->loadMissing(['party', 'product']), 'pass' => $pass->data, 'issuedAt' => $pass->occurred_at,
            'code' => SlipRenderer::verificationCode($entry->id, $pass->data ?? []),
        ])->setPaper('a5')->output();
    }

    /** "Rupees Four Lakh Twenty-Two Thousand One Hundred Only" (Indian numbering). */
    public static function rupeesInWords(int $paise): string
    {
        $rupees = intdiv($paise, 100);
        $p = $paise % 100;
        $words = 'Rupees '.self::indian($rupees);
        if ($p > 0) {
            $words .= ' and '.self::indian($p).' Paise';
        }

        return $words.' Only';
    }

    private static function indian(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($n >= $unit) {
                $parts[] = self::indian(intdiv($n, $unit)).' '.$name;
                $n %= $unit;
            }
        }
        if ($n > 0) {
            $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
                'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
            $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
            $parts[] = $n < 20 ? $ones[$n] : $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : '');
        }

        return implode(' ', $parts);
    }
}
