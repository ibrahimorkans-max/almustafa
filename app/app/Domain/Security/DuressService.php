<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\SessionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Duress (panic) PIN. Someone forced to sign in types the PIN instead of the password. The sign-in
 * ends with a neutral "Signed out. Please try again later." — no fake data, no fake error — while
 * the Super Admins, the Owners and security contacts are alerted, the account is flagged and, if the
 * user chose so, suspended and the device in use queued for a remote wipe.
 */
final class DuressService
{
    public const NEUTRAL_MESSAGE = 'Signed out. Please try again later.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionService $sessions,
        private readonly DeviceService $devices,
        private readonly SecurityNotifier $notifier,
        private readonly TenantContext $tenant,
    ) {}

    public function setPin(LoginAccount $account, string $currentPassword, string $pin, bool $suspend, bool $wipeDevice): void
    {
        if (! $account->mayUseDuressPin()) {
            throw new BusinessRuleViolation('A duress PIN is available to Owner, Super Admin and Accountant accounts.');
        }
        if (! Hash::check($currentPassword, $account->password)) {
            throw new BusinessRuleViolation('Your current password is not correct.');
        }
        if (! preg_match('/^\d{6,8}$/', $pin)) {
            throw new BusinessRuleViolation('The duress PIN must be 6 to 8 digits.');
        }
        if (preg_match('/^(\d)\1+$/', $pin) || str_contains('0123456789', $pin) || str_contains('9876543210', $pin)) {
            throw new BusinessRuleViolation('Choose a PIN that is not a simple sequence or repeated digit.');
        }
        if (Hash::check($pin, $account->password)) {
            throw new BusinessRuleViolation('The duress PIN must be different from your password.');
        }

        $account->forceFill([
            'duress_pin_hash' => Hash::make($pin), 'duress_pin_set_at' => now(),
            'duress_suspends' => $suspend, 'duress_wipes_device' => $wipeDevice,
        ])->save();
        $this->audit->record('security.duress_pin.set', $account, meta: ['suspends' => $suspend, 'wipes_device' => $wipeDevice]);
    }

    public function removePin(LoginAccount $account, string $currentPassword): void
    {
        if (! Hash::check($currentPassword, $account->password)) {
            throw new BusinessRuleViolation('Your current password is not correct.');
        }
        $account->forceFill(['duress_pin_hash' => null, 'duress_pin_set_at' => null])->save();
        $this->audit->record('security.duress_pin.removed', $account);
    }

    public function matches(LoginAccount $account, string $secret): bool
    {
        return $account->duress_pin_hash !== null && $secret !== '' && Hash::check($secret, $account->duress_pin_hash);
    }

    /** Runs inside the account's entity (the login flow has bound it). */
    public function trigger(LoginAccount $account, ?string $ip, ?string $deviceUid, ?string $userAgent): void
    {
        $device = $deviceUid === null ? null
            : $this->tenant->runAsSystem(fn () => Device::query()->where('login_account_id', $account->id)->where('device_uid', $deviceUid)->first());

        DB::transaction(function () use ($account, $device, $ip, $deviceUid, $userAgent): void {
            $account->forceFill(['duress_flagged_at' => now()])->save();
            $ended = $this->sessions->revokeAllFor($account, 'duress');
            if ($account->duress_suspends) {
                $account->forceFill(['status' => LoginAccount::STATUS_SUSPENDED])->save();
            }
            if ($account->duress_wipes_device && $device !== null && $device->status !== Device::STATUS_WIPED) {
                $this->devices->requestWipe($device, 'duress PIN used on this device');
            }
            $this->audit->record('security.duress.triggered', $account, meta: [
                'ip' => $ip, 'device_uid' => $deviceUid, 'user_agent' => $userAgent, 'sessions_ended' => $ended,
                'suspended' => $account->duress_suspends, 'wipe_requested' => $account->duress_wipes_device && $device !== null,
            ]);
        });

        $who = ($account->person->full_name ?? $account->username)." ({$account->username})";
        $this->notifier->owners($account->entity_id, "Duress sign-in: {$who}",
            "{$who} entered their duress PIN at ".now()->timezone('Asia/Karachi')->format('d M Y H:i:s').' PKT'
            .' from IP '.($ip ?? 'unknown').', device '.($device->name ?? $deviceUid ?? 'unknown').'.'
            .($account->duress_suspends ? ' The account has been suspended.' : ' The account is flagged.')
            .($account->duress_wipes_device && $device !== null ? ' A wipe of that device has been queued.' : '')
            .' Contact the person by phone first; follow the duress runbook before unlocking.');
    }

    /** After the person has been reached and their identity re-verified. */
    public function clearFlag(LoginAccount $account, string $note): void
    {
        if (mb_strlen(trim($note)) < 10) {
            throw new BusinessRuleViolation('Note how the person was reached and their identity re-verified.');
        }
        $account->forceFill(['duress_flagged_at' => null])->save();
        $this->audit->record('security.duress.cleared', $account, meta: ['note' => $note]);
    }
}
