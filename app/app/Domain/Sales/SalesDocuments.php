<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Documents\PdfRenderer;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Sales\SalesBill;
use App\Support\Values\AmountInWords;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** Printed invoice and gate pass, both carrying a verification code over their frozen data. */
final class SalesDocuments
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PdfRenderer $pdf,
    ) {}

    public function invoicePdf(SalesBill $bill): string
    {
        if ($bill->status !== SalesBill::POSTED) {
            throw new BusinessRuleViolation('Only a posted invoice can be printed.');
        }
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($bill->entity_id));

        return $this->pdf->render('sales.invoice', [
            'title' => ($bill->isSalesTaxInvoice() ? 'Sales tax invoice ' : 'Invoice ').$bill->number,
            'bill' => $bill->loadMissing(['party', 'product']),
            'words' => AmountInWords::rupees($bill->total_paise),
        ], $entity, 'A4', (string) $bill->number, self::invoiceCode($bill));
    }

    /** QR code as an SVG data URI (for the IRN's signed QR on the invoice PDF). */
    public static function qrDataUri(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(140, 1),
            new SvgImageBackEnd,
        )))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Verification code printed on the invoice (HMAC over its frozen key figures). */
    public static function invoiceCode(SalesBill $bill): string
    {
        return SlipRenderer::verificationCode($bill->id, [
            'number' => $bill->number, 'bill_date' => $bill->bill_date->toDateString(), 'party_id' => $bill->party_id,
            'quantity_kg' => (string) $bill->quantity_kg, 'taxable_paise' => $bill->taxable_paise, 'sales_tax_paise' => $bill->sales_tax_paise,
            'further_tax_paise' => $bill->further_tax_paise, 'total_paise' => $bill->total_paise,
        ]);
    }

    public function gatePassPdf(GateEntry $entry): string
    {
        $pass = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'gate_pass_issued')->first()
            ?? throw new BusinessRuleViolation('No gate pass has been issued for this vehicle.');
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($entry->entity_id));

        return $this->pdf->render('sales.gate-pass', [
            'title' => 'Gate pass '.($pass->data['number'] ?? ''),
            'entry' => $entry->loadMissing(['party', 'product', 'driver']), 'pass' => $pass->data, 'issuedAt' => $pass->occurred_at,
        ], $entity, 'A5', (string) ($pass->data['number'] ?? ''), SlipRenderer::verificationCode($entry->id, $pass->data ?? []));
    }
}
