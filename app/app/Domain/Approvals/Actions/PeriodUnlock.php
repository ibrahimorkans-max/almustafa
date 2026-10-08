<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Accounts\PeriodLocks;
use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\PeriodLock;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reopening a locked month needs a reason and a second person with `period.lock`. */
final class PeriodUnlock implements ApprovalAction
{
    public const KEY = 'period.unlock';

    public function __construct(private readonly PeriodLocks $periods) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'period.lock';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('period.lock');
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $data = Validator::make($payload, [
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ])->validate();
        if (! PeriodLock::query()->where('period', $data['period'])->where('locked', true)->exists()) {
            throw ValidationException::withMessages(['period' => "{$data['period']} is not locked."]);
        }

        return $data;
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        try {
            $this->periods->unlock((string) $request->payload['period']);
        } catch (BusinessRuleViolation $e) {
            throw new ApprovalDenied($e->getMessage());
        }

        return ['period' => $request->payload['period'], 'locked' => false];
    }
}
