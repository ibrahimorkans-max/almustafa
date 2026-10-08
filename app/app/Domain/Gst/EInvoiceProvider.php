<?php

declare(strict_types=1);

namespace App\Domain\Gst;

/**
 * The mill's GST Suvidha Provider (GSP) connection. A real adapter calls the GSP's API with the
 * credentials from `.env`; the sandbox provider is used for development and tests.
 */
interface EInvoiceProvider
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{irn: string, ack_no: string, ack_at: string, signed_qr: string}
     */
    public function generateIrn(array $payload): array;

    /** @return array{cancelled_at: string} */
    public function cancelIrn(string $irn, string $reasonCode, string $remark): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{eway_bill_no: string, valid_until: string}
     */
    public function generateEwayBill(array $payload): array;
}
