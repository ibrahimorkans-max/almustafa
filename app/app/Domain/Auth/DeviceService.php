<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Approvals\Actions\TrustDevice;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Models\ApprovalRequest;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;

final class DeviceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovalService $approvals,
        private readonly SessionService $sessions,
    ) {}

    /**
     * Finds or registers the device for this account and decides whether it may sign in.
     *
     * @throws AuthenticationFailed
     */
    public function resolveForLogin(LoginAccount $account, DeviceInfo $info, ?string $ip): Device
    {
        $device = Device::query()
            ->where('login_account_id', $account->id)
            ->where('device_uid', $info->uid)
            ->first();

        if ($device === null) {
            $device = Device::query()->create([
                'entity_id' => $account->entity_id,
                'login_account_id' => $account->id,
                'device_uid' => $info->uid,
                'name' => $info->name,
                'platform' => $info->platform,
                'status' => Device::STATUS_ACTIVE,
            ]);
        }

        if (! $device->isUsable()) {
            $this->audit->record('auth.login.blocked', $account, meta: ['reason' => 'device_'.$device->status, 'device_id' => $device->id]);

            throw new AuthenticationFailed(AuthenticationFailed::DEVICE_NOT_ALLOWED, 'device_'.$device->status, 403);
        }

        if ($account->isSuperAdmin() && config('ricemill.security.super_admin_require_trusted_device') && ! $device->isTrusted()) {
            $this->handleUntrustedSuperAdminDevice($account, $device);
        }

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $ip])->save();

        return $device;
    }

    public function revoke(Device $device, string $reason): void
    {
        DB::transaction(function () use ($device, $reason): void {
            $device->status = Device::STATUS_REVOKED;
            $device->save();
            $this->sessions->revokeForDevice($device, 'device_revoked');
            $this->audit->record('device.revoked', $device, meta: ['reason' => $reason]);
        });
    }

    /**
     * The device keeps its sessions so it can receive the instruction: every API call then
     * answers 403 wipe_required until the device deletes its local data and acknowledges.
     */
    public function requestWipe(Device $device, string $reason): void
    {
        DB::transaction(function () use ($device, $reason): void {
            $device->status = Device::STATUS_WIPE_PENDING;
            $device->wipe_requested_at = now();
            $device->wipe_requested_by = app(ActorContext::class)->accountId();
            $device->save();
            $this->audit->record('device.wipe_requested', $device, meta: ['reason' => $reason]);
        });
    }

    /** Called by the device after it erased its local data. Ends all its sessions. */
    public function acknowledgeWipe(Device $device): void
    {
        DB::transaction(function () use ($device): void {
            $device->status = Device::STATUS_WIPED;
            $device->wiped_at = now();
            $device->save();
            $this->sessions->revokeForDevice($device, 'wiped');
            $this->audit->record('device.wiped', $device);
        });
    }

    public function trust(Device $device, ?string $trustedBy, string $how): void
    {
        $device->trusted_at = now();
        $device->trusted_by = $trustedBy;
        $device->save();
        $this->audit->record('device.trusted', $device, meta: ['how' => $how]);
    }

    /**
     * Either trusts the device (first device during installation) or files a
     * maker-checker request for another Super Admin and refuses the login.
     *
     * @throws AuthenticationFailed
     */
    private function handleUntrustedSuperAdminDevice(LoginAccount $account, Device $device): void
    {
        $hasTrusted = Device::query()->where('login_account_id', $account->id)->whereNotNull('trusted_at')->exists();

        if (! $hasTrusted && config('ricemill.security.bootstrap_trust_first_device')) {
            $this->trust($device, null, 'bootstrap_first_device');

            return;
        }

        $pending = ApprovalRequest::query()
            ->where('action', TrustDevice::KEY)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->get()
            ->contains(fn (ApprovalRequest $r) => ($r->payload['device_id'] ?? null) === $device->id);

        if (! $pending) {
            $this->approvals->submitFor($account, TrustDevice::KEY, ['device_id' => $device->id],
                "Trust new Super Admin device '".($device->name ?? $device->platform)."' for {$account->username}",
                'Automatic request: sign-in attempt from an untrusted device', systemInitiated: true);
        }

        $this->audit->record('auth.login.blocked', $account, meta: ['reason' => 'device_not_trusted', 'device_id' => $device->id]);

        throw new AuthenticationFailed(AuthenticationFailed::DEVICE_PENDING_APPROVAL, 'device_not_trusted', 403);
    }
}
