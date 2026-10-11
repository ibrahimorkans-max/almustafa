<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Accounts\AccountsDocuments;
use App\Domain\Documents\Engine\DocumentTypes;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Sales\SalesDocuments;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PurchaseBill;
use App\Models\Documents\GeneratedDocument;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Sales\SalesBill;
use App\Support\Values\Money;

/**
 * Public check of a printed weighment slip, invoice, gate pass, purchase bill or payment voucher: the document number plus the
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
            // HR documents: number, issue date and validity only — never the contents.
            $base = (string) preg_replace('/\s+R\d+$/', '', $number);
            foreach (GeneratedDocument::query()->where('number', $base)->get() as $doc) {
                if ($doc->displayNumber() === $number && hash_equals($doc->verify_code, $code)) {
                    $next = GeneratedDocument::query()->where('supersedes_id', $doc->id)->first();

                    return ['type' => DocumentTypes::get($doc->doc_type)->label(), 'facts' => [
                        'Document' => $doc->displayNumber(), 'Issued' => $doc->created_at->timezone('Asia/Karachi')->format('d M Y'),
                        'Status' => $next === null ? 'Valid' : 'Replaced by '.$next->displayNumber().' on '.$next->created_at->timezone('Asia/Karachi')->format('d M Y'),
                    ]];
                }
            }

            $bill = SalesBill::query()->where('number', $number)->where('status', SalesBill::POSTED)->first();
            if ($bill !== null && hash_equals(SalesDocuments::invoiceCode($bill), $code)) {
                return ['type' => $bill->isSalesTaxInvoice() ? 'Sales tax invoice' : 'Invoice', 'facts' => [
                    'Invoice' => (string) $bill->number, 'Date' => $bill->bill_date->format('d M Y'),
                    'Quantity (kg)' => (string) $bill->quantity_kg, 'Invoice total' => Money::paise($bill->total_paise)->format(),
                    'Vehicle' => (string) ($bill->snapshot['registration_no'] ?? ''),
                ]];
            }

            $purchase = PurchaseBill::query()->where('number', $number)->where('status', PurchaseBill::POSTED)->first();
            if ($purchase !== null && hash_equals(AccountsDocuments::purchaseBillCode($purchase), $code)) {
                return ['type' => 'Purchase bill', 'facts' => [
                    'Bill' => (string) $purchase->number, 'Date' => $purchase->bill_date->format('d M Y'),
                    'Payable weight (kg)' => (string) $purchase->payable_kg, 'Net payable' => Money::paise($purchase->net_paise)->format(),
                    'Weighment slip' => (string) ($purchase->snapshot['gate_entry_number'] ?? ''),
                ]];
            }

            $payment = Payment::query()->where('number', $number)->where('status', Payment::APPROVED)->first();
            if ($payment !== null && hash_equals(AccountsDocuments::paymentCode($payment), $code)) {
                return ['type' => 'Payment voucher', 'facts' => [
                    'Voucher' => $payment->number, 'Date' => $payment->payment_date->format('d M Y'),
                    'Mode' => Payment::MODES[$payment->mode] ?? $payment->mode, 'Amount' => Money::paise($payment->amount_paise)->format(),
                ]];
            }

            $entry = GateEntry::query()->where('number', $number)->first();
            if ($entry !== null) {
                $slip = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'slip_issued')->first();
                if ($slip !== null && hash_equals(SlipRenderer::verificationCode($entry->id, $slip->data ?? []), $code)) {
                    return ['type' => 'Weighment slip', 'facts' => [
                        'Slip' => (string) $entry->number, 'Vehicle' => $entry->registration_no,
                        'Issued' => $slip->occurred_at->timezone('Asia/Karachi')->format('d M Y H:i'),
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
                    'Issued' => $pass->occurred_at->timezone('Asia/Karachi')->format('d M Y H:i'),
                    'Net weight (kg)' => (string) ($pass->data['net_kg'] ?? ''), 'Invoice' => (string) ($pass->data['invoice_number'] ?? ''),
                ]];
            }

            return null;
        });
    }
}
