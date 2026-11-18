<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\SecurityNotifier;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A web sign-in from a network the login may not use (its IP allow-list, or an Owner's required list) by somebody who
 * has finished the first sign-in: after the right password (and 2FA) the session is held on "Verify this new network"
 * with no rights, until the person types the 6-digit code sent to their login's own e-mail (EmailCode) — or, without an
 * e-mail, until somebody signed in approves it with the QR code and number (SignInApprovals). The network
 * (its /24, or /64) is then added and the Owners are told. When neither is possible it is refused as before.
 *
 * A web sign-in from a device not approved yet is held the same way, by somebody with an e-mail on the login: the same
 * code approves the device (nobody else has to). One code lets go of both when both are new.
 */
final class NewNetwork
{
    /** Request attribute: this request belongs to a held session (the account has no rights in it). */
    public const HELD = 'rm_network_held';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ricemill.auth.new_network_check', true);
    }

    /** Whether this person can prove a new network: by the e-mail on the login, or by somebody who can approve it. */
    public function possible(LoginAccount $account): bool
    {
        return $this->enabled() && ! $account->needsFirstSignIn() && ! $account->is_machine
            && ($this->byEmail($account) || $this->canAddEmail($account) || app(SignInApprovals::class)->approvers($account)->isNotEmpty());
    }

    /**
     * A login with no e-mail yet may give one on the check screen and prove the sign-in with its code — only when it
     * has its authenticator (2FA) set up: the person then got past the password and the authenticator code; the
     * address is kept from then on (EmailCode) and the Owners are told.
     */
    public function canAddEmail(LoginAccount $account): bool
    {
        return blank($account->email) && $account->hasMfa();
    }

    /** The check is the code to the login's own e-mail (else the QR approval). */
    public function byEmail(LoginAccount $account): bool
    {
        return filled($account->email);
    }

    public function allowed(LoginAccount $account, ?string $ip): bool
    {
        return $this->tenant->runAsSystem(fn () => app(IpAllowlistPolicy::class)->allows($account, $ip));
    }

    /** The sign-in is held until the network is proven. */
    public function hold(AuthSession $session): void
    {
        $this->tenant->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->whereKey($session->id)->toBase()->update(['network_pending_at' => now()]));
        $session->forceFill(['network_pending_at' => now()]);
        $account = $this->tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->find($session->login_account_id));
        $this->audit->record('auth.new_network.held', $account, meta: ['ip' => $session->ip, 'session_id' => $session->id]);
    }

    /** Whether a device not approved yet can be proven by the code sent to the login's e-mail (instead of an Owner). */
    public function canProveDevice(LoginAccount $account): bool
    {
        return (bool) config('ricemill.auth.new_device_email_check', true) && ! $account->needsFirstSignIn() && ! $account->is_machine
            && ($this->byEmail($account) || $this->canAddEmail($account));
    }

    /** The sign-in is held until its device is proven by the e-mail code. */
    public function holdDevice(AuthSession $session): void
    {
        $this->tenant->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->whereKey($session->id)->toBase()->update(['device_pending_at' => now()]));
        $session->forceFill(['device_pending_at' => now()]);
        $account = $this->tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->find($session->login_account_id));
        $this->audit->record('auth.new_device.held', $account, meta: ['ip' => $session->ip, 'session_id' => $session->id, 'device_id' => $session->device_id]);
    }

    public static function isHeld(?Request $request = null): bool
    {
        return (bool) ($request ?? request())->attributes->get(self::HELD, false);
    }

    /** @param  string|null  $firstEmail  a login without an e-mail gives it here (canAddEmail); it is kept */
    public function sendCode(LoginAccount $account, AuthSession $session, ?string $firstEmail = null): void
    {
        if (blank($account->email) && ! $this->canAddEmail($account)) {
            throw new BusinessRuleViolation('This login has no e-mail. Ask your administrator to add it (Security → Logins).');
        }
        $added = blank($account->email);
        $purpose = $session->network_pending_at === null && $session->device_pending_at !== null ? EmailCode::NEW_DEVICE : EmailCode::NEW_NETWORK;
        app(EmailCode::class)->send($account, $firstEmail, $session->id, $purpose,
            signInInfo: array_filter(['ip' => $session->ip, 'browser' => $session->browser, 'os' => $session->os, 'city' => $session->city, 'country' => $session->country_name ?? $session->country]));
        if ($added) {
            $this->audit->record('auth.security_email.added_at_sign_in', $account, meta: ['email' => FirstSignIn::masked((string) $account->email), 'ip' => $session->ip]);
            app(SecurityNotifier::class)->owners($account->entity_id, "E-mail added at a sign-in: {$account->username}",
                "{$account->username} had no e-mail and gave ".FirstSignIn::masked((string) $account->email)." while signing in from {$session->ip} "
                .'(after the password and the authenticator code). Security codes now go there. If this was not them, suspend the login.', includeOwners: true);
        }
    }

    /**
     * After the e-mail code, the second check: the QR code scanned and the number picked on a device already signed in
     * — the person's own ("Is it you?") or an Owner's / administrator's — or, with no device to scan it, a code sent to
     * the mobile number (MobileCode). Skipped (and recorded) only when none of these is possible, so a sole SaaS owner
     * is never shut out.
     */
    public function approvalNeeded(LoginAccount $account, AuthSession $session): bool
    {
        return (bool) config('ricemill.auth.held_sign_in_approval', true)
            && (app(SignInApprovals::class)->canBeApproved($account, $session->id) || app(MobileCode::class)->available($account));
    }

    /**
     * The second check by the code sent to the mobile (instead of the QR code + number): after the e-mail code, the sign-in
     * is let go.
     *
     * @return array{network: string|null, device: string|null}
     */
    public function verifyMobile(LoginAccount $account, AuthSession $session, string $code): array
    {
        $session = $this->tenant->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->find($session->id)) ?? $session;
        if (($this->byEmail($account) || $this->canAddEmail($account)) && $session->code_proven_at === null) {
            throw new BusinessRuleViolation('Confirm the code from the e-mail first.');
        }
        app(MobileCode::class)->check($account, $session, $code);

        return $this->release($account, $session, 'e-mail code + mobile code');
    }

    /**
     * The code from the e-mail. When somebody can approve the sign-in it is the first of two checks (marked; the QR code
     * and number come next, SignInApprovals); otherwise whatever this sign-in waited for is let go now.
     *
     * @return array{network: string|null, device: string|null, approval: bool} the network added, the device approved,
     *                                                                          or that the approval comes next
     */
    public function verifyCode(LoginAccount $account, AuthSession $session, string $code, ?string $ip): array
    {
        app(EmailCode::class)->check($account, $code, $session->id);
        if ($this->approvalNeeded($account, $session)) {
            $this->tenant->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->whereKey($session->id)->toBase()->update(['code_proven_at' => now()]));
            $session->forceFill(['code_proven_at' => now()]);
            $this->audit->record('auth.held_sign_in.code_proven', $account, meta: ['session_id' => $session->id]);

            return ['network' => null, 'device' => null, 'approval' => true];
        }
        if (config('ricemill.auth.held_sign_in_approval', true)) {
            $this->audit->record('auth.held_sign_in.approval_skipped', $account, meta: ['reason' => 'nobody_can_approve', 'session_id' => $session->id]);
        }

        return $this->release($account, $session, 'e-mail code', $ip) + ['approval' => false];
    }

    /**
     * Lets the held sign-in go: the network of this request is added, the device approved.
     *
     * @return array{network: string|null, device: string|null}
     */
    public function release(LoginAccount $account, AuthSession $session, string $how, ?string $ip = null): array
    {
        $session = $this->tenant->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->find($session->id)) ?? $session;

        return [
            'network' => $session->network_pending_at !== null ? $this->allow($account, $session, $ip ?? $session->ip, $how) : null,
            'device' => $session->device_pending_at !== null ? $this->approveDevice($account, $session) : null,
        ];
    }

    /**
     * The held device is approved by the e-mail code (nobody else had to) and the session goes on. The Owners are told.
     *
     * @return string the device's name
     */
    public function approveDevice(LoginAccount $account, AuthSession $session): string
    {
        $device = $this->tenant->runAsSystem(function () use ($session): ?Device {
            $device = $session->device_id !== null ? Device::query()->withoutGlobalScopes()->find($session->device_id) : null;
            if ($device !== null && ! $device->isTrusted() && $device->isUsable()) {
                app(DeviceService::class)->trust($device, null, 'email_code');
            }
            AuthSession::query()->withoutGlobalScopes()->whereKey($session->id)->toBase()->update(['device_pending_at' => null]);

            return $device;
        });
        $session->forceFill(['device_pending_at' => null]);
        $name = $device === null ? 'device' : (string) ($device->name ?? $device->platform);
        $this->audit->record('auth.new_device.approved', $account, meta: ['device_id' => $device?->id, 'ip' => $session->ip, 'how' => 'e-mail code']);
        app(SecurityNotifier::class)->owners($account->entity_id, "New device approved: {$account->username}",
            "{$account->username} signed in on a new device ({$name}) from {$session->ip} and proved it with the code sent to their e-mail: "
            .'the device is approved. If this was not them, remove the device (Security → Devices) and suspend the login.', includeOwners: true);

        return $name;
    }

    /**
     * Adds the network (unless one listed already covers the address) and lets the held session go. The Owners are told.
     *
     * @return string the network added (or the one already covering it)
     */
    public function allow(LoginAccount $account, AuthSession $session, ?string $ip, string $how): string
    {
        $ip ??= (string) $session->ip;
        $cidr = $this->tenant->runAsSystem(function () use ($account, $session, $ip): string {
            $listed = IpAllowlistEntry::query()->withoutGlobalScopes()->where('login_account_id', $account->id)->pluck('cidr')->all();
            $cidr = FirstSignIn::network($ip);
            if (! IpUtils::checkIp($ip, $listed)) {
                IpAllowlistEntry::query()->withoutGlobalScopes()->create([
                    'entity_id' => $account->entity_id, 'login_account_id' => $account->id, 'cidr' => $cidr,
                    'note' => 'Added at a sign-in from a new network ('.now()->timezone('Asia/Karachi')->format('d M Y').')',
                ]);
            }
            AuthSession::query()->withoutGlobalScopes()->whereKey($session->id)->toBase()->update(['network_pending_at' => null]);

            return $cidr;
        });
        $session->forceFill(['network_pending_at' => null]);
        $this->audit->record('auth.new_network.allowed', $account, meta: ['cidr' => $cidr, 'ip' => $ip, 'how' => $how]);
        app(SecurityNotifier::class)->owners($account->entity_id, "New network allowed: {$account->username}",
            "{$account->username} signed in from {$ip} and proved it ({$how}): the network {$cidr} was added to the login's networks. "
            .'If this was not them, suspend the login and remove the network.', includeOwners: true);

        return $cidr;
    }
}
