<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Sales\SalesBillService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PurchaseBill;
use App\Models\Accounts\ReceiptAllocation;
use App\Models\Accounts\Voucher;
use App\Models\ApprovalRequest;
use App\Models\Claims\Claim;
use App\Models\Claims\ClaimEvent;
use App\Models\LoginAccount;
use App\Models\Sales\SalesBill;
use App\Support\Values\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted voucher (requested with `voucher.reverse`, decided by a different person who
 * can post vouchers). The original stays in the books; the reversal is a new voucher.
 */
final class VoucherReversal implements ApprovalAction
{
    public const KEY = 'voucher.reversal';

    public function __construct(
        private readonly Books $books,
        private readonly PurchaseBillService $bills,
        private readonly SalesBillService $salesBills,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'voucher.reverse';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('ledger.post');
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $data = Validator::make($payload, [
            'voucher_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ])->validate();
        $voucher = Voucher::query()->find($data['voucher_id'])
            ?? throw ValidationException::withMessages(['voucher_id' => 'Voucher not found.']);
        if ($voucher->isReversal() || Voucher::query()->where('reverses_id', $voucher->id)->exists()) {
            throw ValidationException::withMessages(['voucher_id' => 'This voucher is a reversal or was already reversed.']);
        }

        return [
            'voucher_id' => $voucher->id, 'number' => $voucher->number, 'type' => $voucher->type,
            'amount' => Money::paise($voucher->total_paise)->format(), 'reason' => $data['reason'],
        ];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        $voucher = Voucher::query()->find($request->payload['voucher_id']) ?? throw new ApprovalDenied('Voucher not found.');
        $reason = (string) $request->payload['reason'];

        try {
            if ($voucher->source_type === 'purchase_bill') {
                $bill = PurchaseBill::query()->lockForUpdate()->findOrFail($voucher->source_id);
                $this->bills->markReversed($bill, $reason);
            }
            if ($voucher->source_type === 'claim') {
                // The note is undone; the claim goes back to "approved" so it can be settled again or withdrawn.
                $claim = Claim::query()->lockForUpdate()->findOrFail($voucher->source_id);
                $claim->forceFill(['status' => Claim::APPROVED, 'voucher_id' => null])->save();
                ClaimEvent::query()->create([
                    'entity_id' => $claim->entity_id, 'claim_id' => $claim->id, 'event' => 'note_reversed',
                    'data' => ['voucher' => $voucher->number, 'reason' => $reason],
                    'actor_login_account_id' => $checker->id, 'actor_person_id' => $checker->person_id, 'created_at' => now(),
                ]);
            }
            if ($voucher->source_type === 'sales_bill') {
                $this->salesBills->markReversed(SalesBill::query()->lockForUpdate()->findOrFail($voucher->source_id), $reason);
            }
            if ($voucher->type === 'receipt') {
                ReceiptAllocation::query()->where('voucher_id', $voucher->id)->delete();
            }
            if ($voucher->source_type === 'payment') {
                Payment::query()->whereKey($voucher->source_id)->first()?->forceFill(['status' => Payment::REVERSED])->save();
            }
            $reversal = $this->books->reverse($voucher, $reason);
        } catch (BusinessRuleViolation $e) {
            throw new ApprovalDenied($e->getMessage());
        }

        return ['reversal_voucher_id' => $reversal->id, 'number' => $reversal->number];
    }
}
