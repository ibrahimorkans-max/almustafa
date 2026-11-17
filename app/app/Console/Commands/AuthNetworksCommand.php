<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\FirstSignIn;
use App\Domain\Auth\IpAllowlistPolicy;
use App\Domain\Tenancy\TenantContext;
use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;
use Illuminate\Console\Command;

/**
 * For the server's administrator when a login is refused with "Sign-in is not allowed from this network" (an Owner
 * must use listed networks): shows the login's networks and whether its first sign-in is done, and can allow a
 * network (--allow, an address or range; a single address allows its /24), remove one, or let it do the first
 * sign-in again (--first-sign-in: any network until the e-mail is confirmed, then that network is added).
 */
final class AuthNetworksCommand extends Command
{
    protected $signature = 'auth:networks
        {username : The login}
        {--allow= : An address or range to allow, e.g. 149.40.161.14 (allows 149.40.161.0/24) or 149.40.0.0/16}
        {--remove= : A listed range to remove}
        {--first-sign-in : Let the login do the first sign-in again (e-mail check, then this network is kept)}
        {--test= : An address to check, e.g. the one in auth:sign-ins}';

    protected $description = 'See and change the networks a login (e.g. an Owner) may sign in from';

    public function handle(TenantContext $tenant, AuthContextBinder $binder, AuditLogger $audit): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        $account = $tenant->runAsSystem(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->where('username', $username)->first());
        if ($account === null) {
            $this->error("No login {$username}. Check the spelling (usernames are small letters, e.g. amr.owner).");

            return self::FAILURE;
        }
        $binder->bind($account);

        if (($allow = trim((string) $this->option('allow'))) !== '') {
            $cidr = str_contains($allow, '/') ? $allow : FirstSignIn::network($allow);
            [$ip, $bits] = explode('/', $cidr, 2) + [1 => ''];
            $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : (filter_var($ip, FILTER_VALIDATE_IP) !== false ? 128 : 0);
            if ($max === 0 || ! ctype_digit($bits) || (int) $bits > $max || (int) $bits < ($max === 32 ? 8 : 32)) {
                $this->error("{$allow} is not an address or range (e.g. 149.40.161.14 or 149.40.161.0/24).");

                return self::FAILURE;
            }
            $tenant->runAsSystem(fn () => IpAllowlistEntry::query()->withoutGlobalScopes()->firstOrCreate(
                ['login_account_id' => $account->id, 'cidr' => $cidr],
                ['entity_id' => $account->entity_id, 'note' => 'Added by the server administrator'],
            ));
            $audit->record('auth.ip_allowlist.added', $account, meta: ['cidr' => $cidr, 'by' => 'server command']);
            $this->info("{$cidr} allowed for {$account->username}.");
        }
        if (($remove = trim((string) $this->option('remove'))) !== '') {
            $gone = $tenant->runAsSystem(fn () => IpAllowlistEntry::query()->withoutGlobalScopes()
                ->where('login_account_id', $account->id)->where('cidr', $remove)->delete());
            $audit->record('auth.ip_allowlist.removed', $account, meta: ['cidr' => $remove, 'by' => 'server command']);
            $gone > 0 ? $this->info("{$remove} removed.") : $this->warn("{$remove} was not listed.");
        }
        if ($this->option('first-sign-in')) {
            $tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($account->id)->toBase()
                ->update(['first_sign_in_completed_at' => null, 'first_sign_in_at' => null, 'email_verified_at' => null, 'email_verify_token_hash' => null]));
            $audit->record('auth.first_sign_in.reset', $account, meta: ['by' => 'server command']);
            $this->info("{$account->username} will do the first sign-in again (e-mail check) and may start it from any network.");
        }

        $account = $tenant->runAsSystem(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->findOrFail($account->id));
        $cidrs = $tenant->runAsSystem(fn () => IpAllowlistEntry::query()->withoutGlobalScopes()->where('login_account_id', $account->id)->pluck('cidr')->all());
        $required = $account->isOwner() && (bool) config('ricemill.security.super_admin_require_ip_allowlist');

        $this->newLine();
        $this->line("{$account->username} ({$account->role?->name}, mill {$account->entity?->code})");
        $this->line('Owner must use listed networks: '.($required ? 'yes (RICEMILL_SA_REQUIRE_IP_ALLOWLIST)' : 'no'));
        $this->line('First sign-in: '.($account->needsFirstSignIn() ? 'not done yet (any network is let in until it is)'
            : ($account->first_sign_in_completed_at !== null ? 'done '.$account->first_sign_in_completed_at->toDateTimeString() : 'not needed')));
        $this->line('Listed networks: '.($cidrs === [] ? 'none'.($required && ! $account->needsFirstSignIn() ? ' - so this login is refused from everywhere' : '') : implode(', ', $cidrs)));

        if (($test = trim((string) $this->option('test'))) !== '') {
            $ok = $tenant->runAsSystem(fn () => app(IpAllowlistPolicy::class)->allows($account, $test));
            $ok ? $this->info("{$test}: allowed.") : $this->error("{$test}: refused.");
            if (! $ok) {
                $this->line("Allow it with: php artisan auth:networks {$account->username} --allow={$test}");
            }
        }

        return self::SUCCESS;
    }
}
