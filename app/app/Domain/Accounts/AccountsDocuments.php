<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Documents\PdfRenderer;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PurchaseBill;
use App\Models\ApprovalRequest;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Support\Values\AmountInWords;

/**
 * Printed paddy purchase bill (for the grower / arhti) and payment voucher, each with a verification
 * code over its frozen figures, so a printout can be checked against the books at /verify.
 */
final class AccountsDocuments
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PdfRenderer $pdf,
    ) {}

    public function purchaseBillPdf(PurchaseBill $bill): string
    {
        if ($bill->status !== PurchaseBill::POSTED) {
            throw new BusinessRuleViolation('Only a posted purchase bill can be printed.');
        }
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($bill->entity_id));

        return $this->pdf->render('accounts.purchase-bill', [
            'title' => 'Purchase bill '.$bill->number,
            'bill' => $bill->loadMissing(['party', 'product', 'gateEntry']),
            'words' => AmountInWords::rupees($bill->net_paise),
        ], $entity, 'A4', (string) $bill->number, self::purchaseBillCode($bill));
    }

    public function paymentVoucherPdf(Payment $payment): string
    {
        if ($payment->status !== Payment::APPROVED) {
            throw new BusinessRuleViolation('Only an approved payment has a voucher to print.');
        }
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($payment->entity_id));

        // Who made it and who approved it (the last approval stage), printed above the signature lines.
        $checker = $payment->approval_request_id === null ? null
            : $this->tenant->runAsSystem(fn () => ApprovalRequest::query()->find($payment->approval_request_id)?->checker_login_account_id);

        return $this->pdf->render('accounts.payment-voucher', [
            'title' => 'Payment voucher '.$payment->number,
            'payment' => $payment->loadMissing(['party', 'fromLedger', 'allocations.bill']),
            'words' => AmountInWords::rupees($payment->amount_paise),
            'preparedBy' => $this->personName($payment->created_by),
            'approvedBy' => $this->personName($checker),
        ], $entity, 'A5', $payment->number, self::paymentCode($payment));
    }

    private function personName(?string $accountId): ?string
    {
        $account = $accountId === null ? null : $this->tenant->runAsSystem(fn () => LoginAccount::query()->with('person')->find($accountId));

        return $account === null ? null : ($account->person->full_name ?? $account->username);
    }

    public static function purchaseBillCode(PurchaseBill $bill): string
    {
        return SlipRenderer::verificationCode($bill->id, [
            'number' => $bill->number, 'bill_date' => $bill->bill_date->toDateString(), 'party_id' => $bill->party_id,
            'payable_kg' => (string) $bill->payable_kg, 'gross_paise' => $bill->gross_paise, 'rate_cut_paise' => $bill->rate_cut_paise,
            'wht_paise' => $bill->wht_paise, 'net_paise' => $bill->net_paise,
        ]);
    }

    public static function paymentCode(Payment $payment): string
    {
        return SlipRenderer::verificationCode($payment->id, [
            'number' => $payment->number, 'payment_date' => $payment->payment_date->toDateString(), 'party_id' => $payment->party_id,
            'mode' => $payment->mode, 'amount_paise' => $payment->amount_paise,
        ]);
    }
}
