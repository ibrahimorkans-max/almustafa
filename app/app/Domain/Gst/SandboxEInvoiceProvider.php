<?php

declare(strict_types=1);

namespace App\Domain\Gst;

use App\Domain\Documents\DocumentNumbers;
use Carbon\CarbonImmutable;

/**
 * Development / test stand-in for the GSP. Computes the IRN the way the IRP does
 * (SHA-256 of seller GSTIN + financial year + document type + document number) and returns
 * clearly marked SANDBOX acknowledgements. It never contacts the internet.
 */
final class SandboxEInvoiceProvider implements EInvoiceProvider
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function generateIrn(array $payload): array
    {
        $date = CarbonImmutable::createFromFormat('d/m/Y', (string) $payload['DocDtls']['Dt'], 'Asia/Kolkata');
        $irn = self::irn((string) $payload['SellerDtls']['Gstin'], DocumentNumbers::financialYear($date), (string) $payload['DocDtls']['Typ'], (string) $payload['DocDtls']['No']);
        $ack = '1'.str_pad((string) (hexdec(substr($irn, 0, 12)) % 10 ** 14), 14, '0', STR_PAD_LEFT);
        $qr = base64_encode(json_encode([
            'sandbox' => true, 'SellerGstin' => $payload['SellerDtls']['Gstin'], 'BuyerGstin' => $payload['BuyerDtls']['Gstin'],
            'DocNo' => $payload['DocDtls']['No'], 'DocDt' => $payload['DocDtls']['Dt'], 'TotInvVal' => $payload['ValDtls']['TotInvVal'],
            'Irn' => $irn,
        ], JSON_THROW_ON_ERROR));

        return ['irn' => $irn, 'ack_no' => $ack, 'ack_at' => now()->toIso8601String(), 'signed_qr' => 'SANDBOX.'.$qr];
    }

    public function cancelIrn(string $irn, string $reasonCode, string $remark): array
    {
        return ['cancelled_at' => now()->toIso8601String()];
    }

    public function generateEwayBill(array $payload): array
    {
        $number = '7'.str_pad((string) (hexdec(substr(hash('sha256', (string) $payload['Irn']), 0, 12)) % 10 ** 11), 11, '0', STR_PAD_LEFT);
        // Validity: one day per 200 km (regular cargo), ending at midnight.
        $days = max(1, (int) ceil(((int) $payload['Distance']) / 200));

        return ['eway_bill_no' => $number, 'valid_until' => now('Asia/Kolkata')->addDays($days)->endOfDay()->toIso8601String()];
    }

    public static function irn(string $gstin, string $financialYear, string $docType, string $docNumber): string
    {
        return hash('sha256', $gstin.$financialYear.$docType.$docNumber);
    }
}
