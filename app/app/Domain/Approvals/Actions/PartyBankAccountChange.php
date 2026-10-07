<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Audit\AuditLogger;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\PartyBankAccount;
use App\Support\Values\IndianIds;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add / change / deactivate a party's bank account — always maker-checker, because a
 * swapped vendor account number is the classic way to divert payments.
 *
 * The account number and UPI id are stored ENCRYPTED inside the request payload, so neither
 * approval_requests nor the audit log ever holds them in plain text.
 */
final class PartyBankAccountChange implements ApprovalAction
{
    public const KEY = 'party.bank_account.change';

    public function __construct(private readonly AuditLogger $audit) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'master.manage';
    }

    /** The checker must be allowed to see bank details to verify them. */
    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('bank.view');
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $op = $payload['op'] ?? 'add';
        $v = Validator::make($payload, [
            'op' => ['required', Rule::in(['add', 'update', 'deactivate'])],
            'party_id' => ['required', 'uuid'],
            'bank_account_id' => [$op === 'add' ? 'prohibited' : 'required', 'nullable', 'uuid'],
            'account_holder' => [$op === 'deactivate' ? 'nullable' : 'required', 'string', 'max:160'],
            'account_number' => [$op === 'deactivate' ? 'nullable' : 'required', 'regex:/^\d{9,18}$/'],
            'ifsc' => [$op === 'deactivate' ? 'nullable' : 'required', fn ($a, $value, $fail) => IndianIds::isValidIfsc(strtoupper((string) $value)) ? null : $fail('Invalid IFSC code.')],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:120'],
            'upi_id' => ['nullable', 'regex:/^[\w.\-]{2,256}@[a-zA-Z]{2,64}$/'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $data = $v->validate();

        $party = Party::query()->find($data['party_id']) ?? throw ValidationException::withMessages(['party_id' => 'Party not found.']);
        if ($op !== 'add' && ! PartyBankAccount::query()->where('party_id', $party->id)->whereKey($data['bank_account_id'])->exists()) {
            throw ValidationException::withMessages(['bank_account_id' => 'Bank account not found for this party.']);
        }

        $number = $data['account_number'] ?? null;

        return [
            'op' => $op,
            'party_id' => $party->id,
            'party_name' => $party->name,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'account_holder' => $data['account_holder'] ?? null,
            'account_number_encrypted' => $number !== null ? Crypt::encryptString($number) : null,
            'account_number_masked' => $number !== null ? 'XXXX'.substr($number, -4) : null,
            'ifsc' => isset($data['ifsc']) ? strtoupper((string) $data['ifsc']) : null,
            'bank_name' => $data['bank_name'] ?? null,
            'branch' => $data['branch'] ?? null,
            'upi_id_encrypted' => isset($data['upi_id']) ? Crypt::encryptString((string) $data['upi_id']) : null,
            'is_primary' => (bool) ($data['is_primary'] ?? false),
        ];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        $p = $request->payload;
        $party = Party::query()->find($p['party_id']) ?? throw new ApprovalDenied('Party no longer exists.');

        if ($p['op'] === 'deactivate') {
            $account = $this->find($party, (string) $p['bank_account_id']);
            $account->status = 'inactive';
            $account->is_primary = false;
            $account->save();

            return ['bank_account_id' => $account->id, 'status' => 'inactive'];
        }

        $account = $p['op'] === 'add'
            ? new PartyBankAccount(['entity_id' => $party->entity_id, 'party_id' => $party->id, 'status' => 'active'])
            : $this->find($party, (string) $p['bank_account_id']);

        $account->fill([
            'account_holder' => $p['account_holder'],
            'account_number' => Crypt::decryptString((string) $p['account_number_encrypted']),
            'ifsc' => $p['ifsc'],
            'bank_name' => $p['bank_name'],
            'branch' => $p['branch'],
            'upi_id' => $p['upi_id_encrypted'] !== null ? Crypt::decryptString((string) $p['upi_id_encrypted']) : null,
            'is_primary' => $p['is_primary'],
        ]);
        $account->approval_request_id = $request->id;

        if ($account->is_primary) {
            PartyBankAccount::query()->where('party_id', $party->id)
                ->when($account->exists, fn ($q) => $q->whereKeyNot($account->id))
                ->update(['is_primary' => false]);
        }
        $account->save();

        $this->audit->record('party.bank_account.'.$p['op'], $party, meta: [
            'bank_account_id' => $account->id, 'account' => $account->maskedNumber(), 'ifsc' => $account->ifsc,
            'approval_request_id' => $request->id,
        ]);

        return ['bank_account_id' => $account->id, 'account' => $account->maskedNumber()];
    }

    private function find(Party $party, string $id): PartyBankAccount
    {
        return PartyBankAccount::query()->where('party_id', $party->id)->lockForUpdate()->find($id)
            ?? throw new ApprovalDenied('Bank account no longer exists.');
    }
}
