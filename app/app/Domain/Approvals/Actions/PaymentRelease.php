<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Accounts\PaymentService;
use App\Domain\Approvals\ApprovalService;
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
 * Releases a payment. Stage 1 is decided by a different person with `payment.approve`; above the
 * configured amount the first approval only moves the payment to "first approved" and raises a
 * stage-2 request, which needs yet another person (neither the requester nor the first approver).
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

        if ($stage === 1 && $payment->amount_paise > (int) config('ricemill.accounts.second_approval_above_paise')) {
            $payment->forceFill(['status' => Payment::FIRST_APPROVED])->save();
            // The first approver becomes the maker of stage 2, so maker-checker excludes them;
            // the original requester is excluded through the payload.
            $second = app(ApprovalService::class)->submitFor($checker, self::KEY, [
                'payment_id' => $payment->id, 'stage' => 2, 'requester_person_id' => $request->maker_person_id,
            ], "Second approval: {$request->summary}", $request->reason, systemInitiated: true);
            // Re-read: when the Owner or a Super Admin approved stage 1, stage 2 was applied at once and released the payment.
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $payment->forceFill(['approval_request_id' => $second->id])->save();

            return ['status' => $payment->status, 'second_request_id' => $second->id];
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
