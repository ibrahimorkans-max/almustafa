<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\SessionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * For the server's administrator when nobody in the ERP can unlock a login, typically the only Owner: lifts the
 * lock after wrong passwords (the whole account and every address it is locked out from) and tells why sign-in
 * was refused. With --reset-password the login gets a temporary password, written to a private file on the
 * server (never printed), to be changed at the next sign-in; its sessions end.
 */
final class AuthUnlockCommand extends Command
{
    protected $signature = 'auth:unlock
        {username : The login to unlock}
        {--reset-password : Also give it a temporary password (written to storage/app/private, not printed)}';

    protected $description = 'Unlock a login locked by wrong passwords (e.g. the only Owner), optionally with a temporary password';

    public function handle(TenantContext $tenant, AuthContextBinder $binder, AuditLogger $audit): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        $account = $tenant->runAsSystem(fn () => LoginAccount::query()->with(['role', 'entity'])->where('username', $username)->first());
        if ($account === null) {
            $this->error("No login {$username}. Check the spelling (usernames are small letters, e.g. amr.owner).");

            return self::FAILURE;
        }
        $binder->bind($account);

        $this->line("{$account->username} ({$account->role?->name}, mill {$account->entity?->code}): status {$account->status}"
            .($account->entity !== null && ! $account->entity->isActive() ? ", mill {$account->entity->status}" : ''));
        if ($account->isLocked() || LoginService::hasAddressLocks($account)) {
            $this->line('Was locked: '.($account->needs_admin_unlock ? 'until an administrator unlocks it' : 'after wrong passwords')
                .(LoginService::hasAddressLocks($account) ? ' (also locked out from at least one address)' : '').'.');
        } else {
            $this->line('Was not locked: a refused sign-in was a wrong password (or the browser filled in an old one).');
        }

        $account->forceFill(['locked_until' => null, 'failed_attempts' => 0, 'needs_admin_unlock' => false, 'lockouts_24h' => 0])->save();
        LoginService::clearAddressLocks($account);
        $audit->record('auth.account.unlocked', $account, meta: ['by' => 'server command']);
        $this->info("{$account->username} is unlocked.");

        if ($this->option('reset-password')) {
            $password = Str::password(16, symbols: false);
            $account->password = $password;
            $account->must_change_password = true;
            $account->save();
            app(SessionService::class)->revokeAllFor($account, 'password_reset_by_admin');
            $audit->record('auth.password.reset_by_admin', $account, meta: ['reason' => 'server command']);

            $file = storage_path('app/private/unlock-'.$account->username.'.txt');
            File::ensureDirectoryExists(dirname($file));
            File::put($file, implode(PHP_EOL, [
                'Temporary password - keep private, delete this file after the sign-in',
                'Sign in: '.url('/admin/login'),
                'Username: '.$account->username,
                'Temporary password: '.$password,
                'At the sign-in you choose your own password.',
            ]).PHP_EOL);
            $this->info("Temporary password written to {$file} (not shown here).");
        }

        return self::SUCCESS;
    }
}
