<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\PdfRenderer;
use App\Filament\Pages\SubscriptionPage;
use App\Models\LoginAccount;
use App\Models\SaasInvoice;
use Filament\Facades\Filament;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /admin/billing/invoices/{id}: the provider's invoice as a PDF, with the bank details to pay it; and
 * /receipt, the transfer receipt the customer uploaded. The platform admin sees any; a customer's Owner
 * only its own company's.
 */
final class SaasInvoiceController
{
    public function pdf(string $id, PdfRenderer $pdf): Response
    {
        $invoice = $this->invoice($id);
        $bytes = $pdf->render('pdf.saas-invoice', [
            'title' => 'Invoice '.$invoice->number, 'invoice' => $invoice, 'customer' => $invoice->customer,
            'bank' => SubscriptionPage::bankDetails(), 'seller' => (array) config('ricemill.saas.billing'),
            'product' => (string) config('ricemill.saas.product_name'), 'bare' => true,
        ], null, 'A4', paper: 'a4');

        return response($bytes, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"']);
    }

    public function receipt(string $id): StreamedResponse
    {
        $invoice = $this->invoice($id);
        abort_if($invoice->receipt_path === null || ! Storage::disk('local')->exists($invoice->receipt_path), 404);

        return Storage::disk('local')->response($invoice->receipt_path, $invoice->number.'-receipt.'.pathinfo($invoice->receipt_path, PATHINFO_EXTENSION));
    }

    private function invoice(string $id): SaasInvoice
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof LoginAccount, 403);
        $invoice = SaasInvoice::query()->with('customer')->find($id) ?? abort(404);
        abort_unless($user->isPlatformAdmin() || ($user->isOwner() && $user->customerId() === $invoice->customer_id), 404);

        return $invoice;
    }
}
