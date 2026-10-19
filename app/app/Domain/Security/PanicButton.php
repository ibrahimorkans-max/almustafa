<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\SessionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;

/**
 * The panic button, for when a device with an open session is lost or stolen. For the account that
 * presses it, at once:
 *  - phones, the PWA and gate PCs get their local data wiped at their next contact (until then every
 *    API call is refused); browsers are blocked;
 *  - every session is ended, here and on every other device;
 *  - the account is locked until an administrator unlocks it (Users → Unlock), so a stolen password
 *    is useless too;
 *  - the Owner and the mill's Super Admins are alerted.
 * No business data is changed, hidden or deleted.
 */
final class PanicButton
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly SessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{devices_wiped: int, devices_blocked: int, sessions_ended: int} */
    public function press(LoginAccount $account, ?string $note = null): array
    {
        $note = trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 500) : null;

        $result = $this->tenant->runAsSystem(fn () => DB::transaction(function () use ($account, $note): array {
            $wiped = 0;
            $blocked = 0;
            $wiping = [];
            foreach (Device::query()->where('login_account_id', $account->id)->where('status', Device::STATUS_ACTIVE)->get() as $device) {
                if (in_array($device->platform, ['android', 'ios', 'pwa', 'agent'], true)) {
                    $this->devices->requestWipe($device, 'panic button');
                    $wiping[] = $device->id;
                    $wiped++;
                } else {
                    $this->devices->revoke($device, 'panic button');
                    $blocked++;
                }
            }
            // Devices being wiped keep their sessions just long enough to receive the wipe instruction.
            $sessions = AuthSession::query()->where('login_account_id', $account->id)->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('device_id')->orWhereNotIn('device_id', $wiping ?: ['00000000-0000-0000-0000-000000000000']))->get();
            $sessions->each(fn (AuthSession $s) => $this->sessions->revoke($s, 'panic_button'));

            $account->forceFill(['needs_admin_unlock' => true, 'locked_until' => null])->save();
            $counts = ['devices_wiped' => $wiped, 'devices_blocked' => $blocked, 'sessions_ended' => $sessions->count()];
            $this->audit->record('security.panic_button', $account, meta: $counts + ['note' => $note]);

            return $counts;
        }));

        app(SecurityNotifier::class)->owners($account->entity_id, "Panic button pressed by {$account->username}",
            "{$account->username} pressed the panic button (lost or stolen device"
            .($note !== null ? ": {$note}" : '').'). '
            ."{$result['sessions_ended']} session(s) ended, {$result['devices_blocked']} browser(s) blocked, {$result['devices_wiped']} device(s) set to wipe. "
            .'The account is locked until an administrator unlocks it (Users → Unlock).');

        return $result;
    }
}
