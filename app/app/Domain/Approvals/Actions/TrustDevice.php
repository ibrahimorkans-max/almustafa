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
 * A new Super Admin device must be approved by a different Super Admin.
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

    /** A Super Admin's device needs another Super Admin; anyone else's, a holder of device.manage (the owner). */
    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        $owner = LoginAccount::query()->withoutGlobalScopes()->find($request->payload['login_account_id'] ?? null);
        if ($owner === null || $owner->isSuperAdmin()) {
            return $checker->isSuperAdmin();
        }

        return $checker->id !== $owner->id && ($checker->isSuperAdmin() || $checker->hasPermission('device.manage'));
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
