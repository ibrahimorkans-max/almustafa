<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Auth\DeviceService;
use App\Models\ApprovalRequest;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Validation\ValidationException;

/**
 * A new Owner device must be approved by a different Owner.
 */
final class TrustDevice implements ApprovalAction
{
    public const KEY = 'device.trust';

    public function __construct(private readonly DeviceService $devices) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'device.manage';
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $device = Device::query()->find($payload['device_id'] ?? null);
        if ($device === null) {
            throw ValidationException::withMessages(['device_id' => 'Device not found.']);
        }

        return ['device_id' => $device->id, 'login_account_id' => $device->login_account_id];
    }

    /** An Owner's device needs another Owner; anyone else's, a holder of device.manage (the mill's Super Admin). */
    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        $owner = LoginAccount::query()->withoutGlobalScopes()->find($request->payload['login_account_id'] ?? null);
        if ($owner === null || $owner->isOwner()) {
            return $checker->isOwner();
        }

        return $checker->id !== $owner->id && ($checker->isOwner() || $checker->hasPermission('device.manage'));
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        $device = Device::query()->find($request->payload['device_id'])
            ?? throw new ApprovalDenied('Device no longer exists.');
        if (! $device->isUsable()) {
            throw new ApprovalDenied("Device is {$device->status}.");
        }

        $this->devices->trust($device, $checker->id, 'approval:'.$request->id);

        return ['device_id' => $device->id];
    }
}
