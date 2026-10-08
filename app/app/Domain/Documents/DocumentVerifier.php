<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Gate\SlipRenderer;
use App\Domain\Sales\SalesDocuments;
use App\Domain\Tenancy\TenantContext;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Sales\SalesBill;
use App\Support\Values\Money;

/**
 * Public check of a printed weighment slip, tax invoice or gate pass: the document number plus the
 * printed verification code. Answers only whether it is genuine and its key facts — nothing else.
 */
final class DocumentVerifier
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array{type: string, facts: array<string, string>}|null null = not genuine / not found */
    public function verify(string $number, string $code): ?array
    {
        $number = trim($number);
        $code = strtoupper(trim($code));
        if ($number === '' || ! preg_match('/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $code)) {
            return null;
        }

        return $this->tenant->runAsSystem(function () use ($number, $code): ?array {
            $bill = SalesBill::query()->where('number', $number)->where('status', SalesBill::POSTED)->first();
            if ($bill !== null && hash_equals(SalesDocuments::invoiceCode($bill), $code)) {
                return ['type' => 'Tax invoice', 'facts' => [
                    'Invoice' => (string) $bill->number, 'Date' => $bill->bill_date->format('d M Y'),
                    'Quantity (kg)' => (string) $bill->quantity_kg, 'Invoice total' => Money::paise($bill->total_paise)->format(),
                    'Vehicle' => (string) ($bill->snapshot['registration_no'] ?? ''),
                ]];
            }

            $entry = GateEntry::query()->where('number', $number)->first();
            if ($entry !== null) {
                $slip = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'slip_issued')->first();
                if ($slip !== null && hash_equals(SlipRenderer::verificationCode($entry->id, $slip->data ?? []), $code)) {
                    return ['type' => 'Weighment slip', 'facts' => [
                        'Slip' => (string) $entry->number, 'Vehicle' => $entry->registration_no,
                        'Issued' => $slip->occurred_at->timezone('Asia/Kolkata')->format('d M Y H:i'),
                        'Gross / tare / net (kg)' => sprintf('%s / %s / %s', $slip->data['gross_kg'] ?? '', $slip->data['tare_kg'] ?? '', $slip->data['net_kg'] ?? ''),
                    ]];
                }
            }

            $pass = GateEvent::query()->where('event', 'gate_pass_issued')->get()
                ->first(fn (GateEvent $e) => ($e->data['number'] ?? null) === $number);
            if ($pass !== null && hash_equals(SlipRenderer::verificationCode($pass->gate_entry_id, $pass->data ?? []), $code)) {
                $passEntry = GateEntry::query()->find($pass->gate_entry_id);

                return ['type' => 'Gate pass', 'facts' => [
                    'Gate pass' => $number, 'Vehicle' => (string) $passEntry?->registration_no,
                    'Issued' => $pass->occurred_at->timezone('Asia/Kolkata')->format('d M Y H:i'),
                    'Net weight (kg)' => (string) ($pass->data['net_kg'] ?? ''), 'Invoice' => (string) ($pass->data['invoice_number'] ?? ''),
                ]];
            }

            return null;
        });
    }
}
