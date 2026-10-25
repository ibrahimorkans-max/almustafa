<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Accounts\PaymentService;
use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Contracts\ExplainsCheckerRule;
use App\Domain\Approvals\Contracts\HandlesRejection;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Approvals\SelfApproval;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\Payment;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use Illuminate\Validation\ValidationException;

/**
 * Releases a payment: one approval by someone with `payment.approve`, whatever the amount. It is a
 * different person from the one who entered it, except for the main users (SelfApproval), who release
 * their own payments at once. A payment left "first approved" by the old two-stage rule is released by
 * its pending stage-2 request in the same way.
 */
final class PaymentRelease implements ApprovalAction, ExplainsCheckerRule, HandlesRejection
{
    public const KEY = 'payment.release';

    public function __construct(private readonly PaymentService $payments) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'payment.create';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        if (! $checker->hasPermission('payment.approve')) {
            return false;
        }
        $requester = $request->payload['requester_person_id'] ?? null;

        return $requester === null || $requester !== $checker->person_id || SelfApproval::allowed($checker);
    }

    public function whyCheckerMayNotDecide(LoginAccount $checker, ApprovalRequest $request): string
    {
        return $checker->hasPermission('payment.approve')
            ? 'The person who entered this payment cannot also release it (second approver needed).'
            : "Releasing payments needs the 'payment.approve' permission.";
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $payment = Payment::query()->find($payload['payment_id'] ?? null)
            ?? throw ValidationException::withMessages(['payment_id' => 'Payment not found.']);
        $stage = (int) ($payload['stage'] ?? 1);
        $expected = $stage === 1 ? Payment::PENDING : Payment::FIRST_APPROVED;
        if ($payment->status !== $expected) {
            throw ValidationException::withMessages(['payment_id' => "Payment is {$payment->status}."]);
        }

        return [
            'payment_id' => $payment->id,
            'stage' => $stage,
            'number' => $payment->number,
            'amount' => Money::paise($payment->amount_paise)->format(),
            'mode' => $payment->mode,
            'payee' => $payment->payee,
            'requester_person_id' => $payload['requester_person_id'] ?? null,
        ];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()->whereKey($request->payload['payment_id'])->lockForUpdate()->first();
        $stage = (int) $request->payload['stage'];
        if ($payment === null || $payment->status !== ($stage === 1 ? Payment::PENDING : Payment::FIRST_APPROVED)) {
            throw new ApprovalDenied('The payment is no longer waiting for this approval.');
        }

        try {
            $payment = $this->payments->release($payment);
        } catch (BusinessRuleViolation $e) {
            throw new ApprovalDenied($e->getMessage());
        }

        return ['status' => $payment->status, 'voucher_id' => $payment->voucher_id];
    }

    public function closed(ApprovalRequest $request): void
    {
        $payment = Payment::query()->whereKey($request->payload['payment_id'] ?? null)->first();
        if ($payment !== null && $payment->isOpen()) {
            $payment->forceFill(['status' => $request->status === ApprovalRequest::STATUS_CANCELLED ? Payment::CANCELLED : Payment::REJECTED])->save();
        }
    }
}
