<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Access\RoleTemplates;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\PartyMessage;
use App\Models\Person;
use App\Models\Role;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A party's own login: a supplier or buyer signs in to see only its vehicles, bills, payments and
 * balance (Filament page "My account"). Made by the office (messages.manage) for a party with a mobile
 * number; the password is new and random, changed by the party at the first sign-in, sent to the party
 * by message and shown once to the person who made it. Making it again gives a new password.
 */
final class PartyAccounts
{
    public function __construct(
        private readonly PartyMessenger $messenger,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{account: LoginAccount, username: string, password: string, text: string, message: PartyMessage|null, new: bool}
     */
    public function grant(Party $party): array
    {
        $me = $this->actor->account();
        if (! $me instanceof LoginAccount || ! $me->hasPermission('messages.manage')) {
            throw new BusinessRuleViolation('You may not make party logins.');
        }
        $mobile = PakistanIds::normalizeMobile($party->phone);
        if ($mobile === null) {
            throw new BusinessRuleViolation('Add the party\'s mobile number first (e.g. 0300-1234567): the login details are sent to it.');
        }
        $password = Str::password(10, symbols: false);

        [$account, $new] = DB::transaction(function () use ($party, $password, $mobile): array {
            $account = $party->login_account_id !== null ? LoginAccount::query()->find($party->login_account_id) : null;
            if ($account !== null) {
                $account->forceFill(['password' => $password, 'must_change_password' => true, 'status' => LoginAccount::STATUS_ACTIVE])->save();

                return [$account, false];
            }
            $person = Person::query()->create(['full_name' => $party->name, 'phone' => $mobile]);
            $account = LoginAccount::query()->create([
                'person_id' => $person->id,
                'role_id' => $this->role($party->entity_id)->id,
                'username' => $this->username($party),
                'password' => $password,
                'status' => LoginAccount::STATUS_ACTIVE,
                'must_change_password' => true,
            ]);
            $party->forceFill(['login_account_id' => $account->id])->saveQuietly();

            return [$account, true];
        });
        $this->audit->record($new ? 'party.login_created' : 'party.login_password_reset', $party, null, null, ['account' => $account->id]);

        $text = MessageText::make($this->messenger->millName($party->entity_id), $new ? 'Your account is ready' : 'Your new password', [
            ['Name', $party->name],
            ['Sign in at', self::portalUrl()],
            ['Username', $account->username, true],
            ['Password', $password, true],
            'You will choose your own password when you first sign in.',
            'You can see your vehicles, weights, bills, payments and balance.',
        ]);
        $message = $this->messenger->deliver($party, 'login', $text, $party, redact: true);

        return ['account' => $account, 'username' => $account->username, 'password' => $password, 'text' => $text, 'message' => $message, 'new' => $new];
    }

    public static function portalUrl(): string
    {
        $url = (string) config('ricemill.messaging.portal_url');

        return $url !== '' ? $url : url('/admin/login');
    }

    /** The mill's Party role (made from the template the first time it is needed). */
    private function role(string $entityId): Role
    {
        $existing = Role::query()->where('entity_id', $entityId)->where('code', RoleTemplates::PARTY)->first();
        if ($existing !== null) {
            return $existing;
        }
        $template = RoleTemplates::get(RoleTemplates::PARTY);
        $role = Role::query()->create([
            'entity_id' => $entityId, 'code' => RoleTemplates::PARTY, 'name' => $template['name'], 'is_system' => true,
            'is_super_admin' => false, 'mfa_required' => $template['mfa_required'], 'session_max_minutes' => $template['session_max_minutes'],
        ]);
        DB::table('role_permissions')->insert(array_map(fn (string $p) => [
            'role_id' => $role->id, 'permission_code' => $p, 'entity_id' => $entityId, 'created_at' => now(),
        ], $template['permissions']));

        return $role;
    }

    /** p.<party code>, made unique. */
    private function username(Party $party): string
    {
        $base = 'p.'.strtolower(preg_replace('/[^A-Za-z0-9]/', '', $party->code) ?: 'party');
        $name = $base;
        // Usernames are unique across every mill: look at all of them.
        $taken = fn (string $n) => app(TenantContext::class)->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->where('username', $n)->exists());
        for ($i = 2; $taken($name); $i++) {
            $name = $base.$i;
        }

        return $name;
    }
}
