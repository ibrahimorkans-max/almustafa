<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\SecurityNotifier;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Mail\FirstSignInVerificationMail;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A new user's first sign-in, in three steps before any screen opens:
 *  1. where it came from is kept: IP, place (from the IP, and the browser's GPS when allowed) and the device;
 *  2. the user gives an e-mail address and confirms it with the button in the e-mail sent to it (a one-time link);
 *  3. the user fills in their own details (name, mobile) and is signed in.
 * Only one column per step is written (never the whole account), so it cannot clash with a change made elsewhere.
 */
final class FirstSignIn
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** Step 1: what the first sign-in came from, kept once. */
    public function capture(LoginAccount $account, AuthSession $session, ?string $deviceUid, ?string $deviceName, mixed $lat = null, mixed $lng = null, mixed $accuracy = null): void
    {
        if (! $account->needsFirstSignIn() || $account->first_sign_in_at !== null) {
            return;
        }
        $gps = is_numeric($lat) && is_numeric($lng);
        $info = array_filter([
            'ip' => $session->ip,
            'browser' => $session->browser,
            'os' => $session->os,
            'device' => $deviceName,
            'device_uid' => $deviceUid,
            'city' => $session->city,
            'region' => $session->region,
            'country' => $session->country_name ?? $session->country,
            'latitude' => $gps ? round((float) $lat, 6) : $session->latitude,
            'longitude' => $gps ? round((float) $lng, 6) : $session->longitude,
            'accuracy_m' => $gps && is_numeric($accuracy) ? (int) $accuracy : null,
            'location_source' => $gps ? 'browser' : $session->location_source,
            'user_agent' => $session->user_agent,
            'auth_session_id' => $session->id,
        ], fn ($v) => $v !== null && $v !== '');
        $this->write($account, ['first_sign_in_at' => now(), 'first_sign_in' => $info]);
        $this->audit->record('auth.first_sign_in.captured', $account, meta: array_diff_key($info, ['user_agent' => true]));
    }

    /** The browser's own position, when it is given after sign-in (more exact than the IP's city). */
    public function addLocation(LoginAccount $account, mixed $lat, mixed $lng, mixed $accuracy): void
    {
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180 || ! $account->needsFirstSignIn()) {
            return;
        }
        $info = ($account->first_sign_in ?? []) + [];
        $info['latitude'] = round((float) $lat, 6);
        $info['longitude'] = round((float) $lng, 6);
        $info['accuracy_m'] = is_numeric($accuracy) ? (int) $accuracy : null;
        $info['location_source'] = 'browser';
        $this->write($account, ['first_sign_in' => array_filter($info, fn ($v) => $v !== null)]);
    }

    /** Step 2: sends the e-mail with the verification button. At most once a minute, five times an hour. */
    public function sendVerification(LoginAccount $account, string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw new BusinessRuleViolation('Enter a valid e-mail address.');
        }
        $key = 'first-sign-in-mail:'.$account->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new BusinessRuleViolation(sprintf('Too many e-mails. Try again in %d minutes.', (int) ceil(RateLimiter::availableIn($key) / 60)));
        }
        if ($account->email_verify_sent_at !== null && $account->email_verify_sent_at->gt(now()->subMinute())) {
            throw new BusinessRuleViolation('An e-mail was sent less than a minute ago. Wait a moment, then send it again.');
        }
        RateLimiter::hit($key, 3600);

        $token = Str::random(48);
        $minutes = (int) config('ricemill.auth.email_verify_minutes', 60);
        $this->write($account, ['email' => $email, 'email_verified_at' => null, 'email_verify_token_hash' => hash('sha256', $token), 'email_verify_sent_at' => now()]);
        $url = URL::temporarySignedRoute('first-sign-in.verify', now()->addMinutes($minutes), ['account' => $account->id, 'token' => $token]);
        Mail::to($email)->send(new FirstSignInVerificationMail($account->fresh() ?? $account, $url, $minutes));
        $this->audit->record('auth.first_sign_in.email_sent', $account, meta: ['email' => self::masked($email)]);
    }

    /** The button in the e-mail: the link works once, for the address it was sent to. */
    public function verify(string $accountId, string $token): LoginAccount
    {
        $account = $this->tenant->runAsSystem(fn () => LoginAccount::query()->find($accountId));
        if ($account === null || $account->email_verify_token_hash === null || ! hash_equals($account->email_verify_token_hash, hash('sha256', $token))) {
            throw new BusinessRuleViolation('This link is not valid any more. Sign in again and send a new e-mail.');
        }
        $this->write($account, ['email_verified_at' => now(), 'email_verify_token_hash' => null]);
        $this->audit->record('auth.first_sign_in.email_verified', $account, meta: ['email' => self::masked((string) $account->email)]);

        return $account;
    }

    /**
     * Step 3: the user's own details; the first sign-in is then done.
     *
     * @param  array{full_name: string, phone: string}  $details
     */
    public function complete(LoginAccount $account, array $details, ?AuthSession $session = null): void
    {
        if ($account->email_verified_at === null) {
            throw new BusinessRuleViolation('Confirm your e-mail address first: press the button in the e-mail.');
        }
        $name = trim((string) $details['full_name']);
        if (mb_strlen($name) < 3) {
            throw new BusinessRuleViolation('Enter your full name.');
        }
        $phone = PakistanIds::normalizeMobile((string) $details['phone']);
        if ($phone === null) {
            throw new BusinessRuleViolation('Enter your mobile number, e.g. 0300-1234567.');
        }
        $this->tenant->runAsSystem(function () use ($account, $name, $phone): void {
            if ($account->person_id !== null) {
                Person::query()->withoutGlobalScopes()->whereKey($account->person_id)->toBase()
                    ->update(['full_name' => mb_substr($name, 0, 150), 'phone' => $phone, 'email' => $account->email, 'updated_at' => now()]);
            }
        });
        $this->write($account, ['first_sign_in_completed_at' => now()]);
        $this->audit->record('auth.first_sign_in.completed', $account);
        if ($session !== null) {
            $this->secureFirstPlace($account, $session);
        }
    }

    /**
     * The device and network the first sign-in was finished on (e-mail confirmed) are the person's own from now
     * on: the device is approved when one must be and none is yet, and an Owner who must use listed networks gets
     * this one (its /24, or /64 for IPv6: home and mobile addresses change within it). The other Owners are told.
     */
    private function secureFirstPlace(LoginAccount $account, AuthSession $session): void
    {
        $done = [];
        $this->tenant->runAsSystem(function () use ($account, $session, &$done): void {
            $devices = app(DeviceService::class);
            $device = $session->device_id !== null ? Device::query()->withoutGlobalScopes()->find($session->device_id) : null;
            if ($device !== null && ! $device->isTrusted() && $device->login_account_id === $account->id && $devices->requiresTrustedDevice($account, $device)
                && ! Device::query()->withoutGlobalScopes()->where('login_account_id', $account->id)->whereNotNull('trusted_at')->exists()) {
                $devices->trust($device, null, 'first_sign_in_email_verified');
                $done[] = 'device '.($device->name ?? $device->platform).' approved';
            }
            $ip = $session->ip;
            if ($ip !== null && $account->isOwner() && config('ricemill.security.super_admin_require_ip_allowlist')
                && ! IpAllowlistEntry::query()->withoutGlobalScopes()->where('login_account_id', $account->id)->exists()) {
                $cidr = self::network($ip);
                IpAllowlistEntry::query()->create([
                    'entity_id' => $account->entity_id, 'login_account_id' => $account->id, 'cidr' => $cidr,
                    'note' => 'Added at the first sign-in (e-mail confirmed)',
                ]);
                $done[] = "network {$cidr} allowed";
            }
        });
        if ($done !== []) {
            $this->audit->record('auth.first_sign_in.secured', $account, meta: ['done' => $done, 'ip' => $session->ip]);
            app(SecurityNotifier::class)->owners($account->entity_id, "First sign-in finished: {$account->username}",
                "{$account->username} confirmed their e-mail from {$session->ip}: ".implode(', ', $done).'. If this was not them, suspend the login.',
                includeOwners: true);
        }
    }

    /** The network an address belongs to: x.y.z.0/24 for IPv4, the /64 for IPv6. */
    public static function network(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24';
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }

        return inet_ntop(substr($bin, 0, 8).str_repeat("\0", 8)).'/64';
    }

    /** @param  array<string, mixed>  $values */
    private function write(LoginAccount $account, array $values): void
    {
        $stored = $values;
        if (array_key_exists('first_sign_in', $stored)) {
            $stored['first_sign_in'] = json_encode($stored['first_sign_in']);
        }
        $this->tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($account->getKey())->toBase()->update($stored));
        $account->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    public static function masked(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 2).str_repeat('*', max(1, mb_strlen($user) - 2)).'@'.$domain;
    }
}
