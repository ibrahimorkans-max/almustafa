<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Approvals\SelfApproval;
use App\Domain\Gate\GateService;
use App\Models\ApprovalRequest;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use App\Support\Values\Weight;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The only way to put a typed weight on record (e.g. indicator failure): requested by a
 * Owner with a mandatory reason, approved by a different person holding
 * `weighment.override`. Stored as source = manual_override and flagged everywhere.
 */
final class ManualWeighmentOverride implements ApprovalAction
{
    public const KEY = 'weighment.manual_override';

    public function __construct(private readonly GateService $gate) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'weighment.override';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('weighment.override');
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        if (! SelfApproval::allowed($maker)) {
            throw ValidationException::withMessages(['maker' => 'Only the Owner or the mill\'s Super Admin may request a manual weight.']);
        }

        $data = Validator::make($payload, [
            'gate_entry_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in([Weighment::GROSS, Weighment::TARE])],
            'weight_kg' => ['required', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'scale_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ])->validate();

        $entry = GateEntry::query()->find($data['gate_entry_id'])
            ?? throw ValidationException::withMessages(['gate_entry_id' => 'Gate entry not found.']);
        if (! $entry->isOpen()) {
            throw ValidationException::withMessages(['gate_entry_id' => 'This vehicle has already left or was cancelled.']);
        }

        return [
            'gate_entry_id' => $entry->id,
            'gate_entry_number' => $entry->number,
            'registration_no' => $entry->registration_no,
            'kind' => $data['kind'],
            'weight_kg' => Weight::kg((string) $data['weight_kg'])->kg,
            'scale_id' => $data['scale_id'] ?? null,
            'reason' => $data['reason'],
        ];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        $p = $request->payload;
        $entry = GateEntry::query()->find($p['gate_entry_id']) ?? throw new ApprovalDenied('Gate entry no longer exists.');

        $weighment = $this->gate->recordWeighment(
            $entry,
            (string) $p['kind'],
            (string) $p['weight_kg'],
            $p['scale_id'] ? Scale::query()->find($p['scale_id']) : null,
            null,
            null,
            now(),
            strict: false,
            source: Weighment::SOURCE_MANUAL,
            reason: (string) $p['reason'],
            approvalRequestId: $request->id,
        );

        return ['weighment_id' => $weighment->id, 'weight_kg' => $weighment->weight_kg];
    }
}
