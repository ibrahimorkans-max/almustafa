<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Access\RoleTemplates;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\LoginService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Device;
use App\Models\Gate\AgentPairing as Pairing;
use App\Models\Gate\Scale;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pairing a weighbridge PC with one code instead of copying five values by hand.
 *
 * The office (device.manage) makes a code for a weighbridge: the PC gets a login of its own (role
 * Weighbridge / Gate Operator, a long random password nobody sees), a registered agent device and its
 * signing secret. On the PC, `ricemill-agent pair` sends the code once and writes all of it into
 * agent.toml, protected for that PC. A code works once, for 30 minutes; only its hash is stored and the
 * settings it carries are erased when it is used. Pairing again replaces the PC's password and gives it
 * a new device (the old one is withdrawn), so a lost or copied agent.toml stops working.
 */
final class AgentPairing
{
    public const MINUTES = 30;

    /** No 0/O, 1/I/L: read off a screen and typed on another PC. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
        private readonly DeviceService $devices,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string|null  $accountId  an existing Weighbridge / Gate Operator login used only by this PC; null makes a new one
     * @return array{code: string, username: string, expires_at: Carbon, new_login: bool}
     */
    public function issue(string $scaleId, ?string $accountId = null, string $pcName = 'Weighbridge PC'): array
    {
        $me = $this->actor->account();
        if (! $me instanceof LoginAccount || ! $me->hasPermission('device.manage')) {
            throw new BusinessRuleViolation('You may not register weighbridge PCs.');
        }
        $entityId = $this->tenant->entityId() ?? throw new BusinessRuleViolation('Choose the mill first.');
        $scale = Scale::query()->find($scaleId) ?? throw new BusinessRuleViolation('Choose the weighbridge.');
        $pcName = trim($pcName) !== '' ? Str::limit(trim($pcName), 120, '') : 'Weighbridge PC';

        $code = $this->newCode();
        $password = Str::password(32, symbols: false);

        [$pairing, $account, $new] = DB::transaction(function () use ($entityId, $scale, $accountId, $pcName, $code, $password, $me): array {
            if ($accountId !== null) {
                $account = LoginAccount::query()->with('role')->find($accountId);
                if ($account === null || $account->role?->code !== RoleTemplates::GATE_OPERATOR) {
                    throw new BusinessRuleViolation('Choose a login with the role Weighbridge / Gate Operator.');
                }
                // A person's login would get a password the person does not know, and lose its sign-in.
                if (! $account->is_machine) {
                    throw new BusinessRuleViolation("{$account->username} is a person's login. Leave PC login empty: the PC gets a login of its own.");
                }
                // The old password is gone, so a lock from wrong tries with it (e.g. the PC retrying) is lifted too.
                $account->forceFill(['password' => $password, 'must_change_password' => false, 'status' => LoginAccount::STATUS_ACTIVE,
                    'locked_until' => null, 'failed_attempts' => 0, 'needs_admin_unlock' => false, 'lockouts_24h' => 0])->save();
                LoginService::clearAddressLocks($account);
                $new = false;
            } else {
                $role = Role::query()->where('entity_id', $entityId)->where('code', RoleTemplates::GATE_OPERATOR)->first()
                    ?? throw new BusinessRuleViolation('This mill has no Weighbridge / Gate Operator role.');
                $person = Person::query()->create(['full_name' => $pcName.' ('.$scale->code.')']);
                // A machine's login: not a staff user of the plan.
                $account = (new LoginAccount)->forceFill([
                    'person_id' => $person->id, 'role_id' => $role->id, 'username' => $this->freeUsername($scale),
                    'password' => $password, 'status' => LoginAccount::STATUS_ACTIVE, 'must_change_password' => false, 'is_machine' => true,
                ]);
                $account->save();
                $new = true;
            }

            // One PC per login: an earlier registration and any unused code for it stop working.
            Device::query()->where('login_account_id', $account->id)->where('platform', 'agent')->where('status', Device::STATUS_ACTIVE)
                ->get()->each(fn (Device $d) => $this->devices->revoke($d, 'paired again'));
            Pairing::query()->where('login_account_id', $account->id)->whereNull('used_at')->update(['expires_at' => now()]);

            $uid = 'agent-'.Str::lower(Str::random(24));
            ['device' => $device, 'secret' => $secret] = $this->devices->registerAgent($account, $uid, $pcName);

            $pairing = Pairing::query()->create([
                'device_id' => $device->id, 'login_account_id' => $account->id, 'scale_id' => $scale->id,
                'code_hash' => self::hash($code), 'expires_at' => now()->addMinutes(self::MINUTES), 'created_by' => $me->id,
                'payload' => [
                    'username' => $account->username, 'password' => $password, 'device_uid' => $uid, 'signing_secret' => $secret,
                    'scale_id' => $scale->id, 'scale_code' => $scale->code, 'scale_name' => $scale->name,
                    'resolution_kg' => (string) ($scale->resolution_kg ?? '10'),
                ],
            ]);

            return [$pairing, $account, $new];
        });
        $this->audit->record('device.agent_pairing_issued', $pairing, meta: ['login_account_id' => $account->id, 'scale_id' => $scale->id, 'new_login' => $new]);

        return ['code' => self::display($code), 'username' => $account->username, 'expires_at' => $pairing->expires_at, 'new_login' => $new];
    }

    /**
     * Used by the PC (no sign-in): the settings for a valid code, once.
     *
     * @return array<string, string>
     */
    public function redeem(string $code, ?string $ip): array
    {
        $hash = self::hash($code);

        return $this->tenant->runAsSystem(function () use ($hash, $ip): array {
            return DB::transaction(function () use ($hash, $ip): array {
                $pairing = Pairing::query()->where('code_hash', $hash)->lockForUpdate()->first();
                if ($pairing === null || ! $pairing->isOpen() || $pairing->payload === null) {
                    throw new BusinessRuleViolation('This pairing code is wrong, already used or older than '.self::MINUTES.' minutes. Make a new one in the ERP.');
                }
                $payload = $pairing->payload;
                $pairing->forceFill(['used_at' => now(), 'used_ip' => $ip, 'payload' => null])->save();
                $this->audit->record('device.agent_paired', $pairing, meta: ['device_id' => $pairing->device_id, 'ip' => $ip], entityId: $pairing->entity_id);

                return $payload;
            });
        });
    }

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /** What was typed, without spaces, dashes or case: "abcd-efgh" = "ABCDEFGH". */
    public static function normalize(string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '';
    }

    private static function display(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4, 4);
    }

    private function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** wb.<weighbridge code>, then .2, .3 … (usernames are unique across all mills). */
    private function freeUsername(Scale $scale): string
    {
        $base = 'wb.'.(Str::slug((string) $scale->code, '') ?: 'pc');
        $taken = fn (string $name) => $this->tenant->runAsSystem(fn () => LoginAccount::query()->where('username', $name)->exists());
        $name = $base;
        for ($n = 2; $taken($name); $n++) {
            $name = $base.'.'.$n;
        }

        return $name;
    }
}
