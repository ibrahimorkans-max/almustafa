<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Contracts\HandlesRejection;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use App\Models\Sales\DispatchOrder;
use App\Support\Values\Money;
use Illuminate\Validation\ValidationException;

/**
 * Lets one dispatch order go beyond the buyer's credit limit. Requested by dispatch, decided by a
 * different person with `approval.decide` who can also take sales orders. Rejection cancels the order.
 */
final class CreditOverride implements ApprovalAction, HandlesRejection
{
    public const KEY = 'sales.credit_override';

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'dispatch.manage';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('sales.order');
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $order = DispatchOrder::query()->with('party')->find($payload['dispatch_order_id'] ?? null)
            ?? throw ValidationException::withMessages(['dispatch_order_id' => 'Dispatch order not found.']);
        if ($order->status !== DispatchOrder::PENDING_CREDIT) {
            throw ValidationException::withMessages(['dispatch_order_id' => 'This order is not waiting for a credit decision.']);
        }

        return [
            'dispatch_order_id' => $order->id, 'number' => $order->number, 'party' => $order->party->name,
            'value' => Money::paise($order->estimated_value_paise)->format(),
            'credit_limit' => Money::paise((int) $order->party->credit_limit_paise)->format(),
        ];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        $order = DispatchOrder::query()->lockForUpdate()->find($request->payload['dispatch_order_id']);
        if ($order === null || $order->status !== DispatchOrder::PENDING_CREDIT) {
            throw new ApprovalDenied('The order is no longer waiting for a credit decision.');
        }
        $order->forceFill(['status' => DispatchOrder::OPEN])->save();

        return ['status' => $order->status];
    }

    public function closed(ApprovalRequest $request): void
    {
        $order = DispatchOrder::query()->find($request->payload['dispatch_order_id'] ?? null);
        if ($order !== null && $order->status === DispatchOrder::PENDING_CREDIT) {
            $order->forceFill(['status' => DispatchOrder::CANCELLED, 'note' => trim(($order->note ?? '')."\nCredit override {$request->status}.")])->save();
        }
    }
}
