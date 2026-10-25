<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Illuminate\Console\Command;

/**
 * Makes (or stops) an Owner account a platform admin: the provider's account that may look across every
 * customer (platform console, whole-system lockdown, maintenance, all-mills settings). Only from the
 * server's command line, never from a screen, so no customer can grant it.
 */
final class SaasPlatformAdminCommand extends Command
{
    protected $signature = 'saas:platform-admin {username : An Owner account} {--revoke : Stop it being a platform admin}';

    protected $description = 'Make an Owner account a platform admin (the provider), or revoke it';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        return $tenant->runAsSystem(function () use ($audit): int {
            $account = LoginAccount::query()->with('role')->where('username', mb_strtolower((string) $this->argument('username')))->first();
            if ($account === null || ! $account->isOwner()) {
                $this->error('No Owner account with that username.');

                return self::FAILURE;
            }
            $on = ! $this->option('revoke');
            $account->forceFill(['is_platform_admin' => $on])->save();
            $audit->record($on ? 'saas.platform_admin_granted' : 'saas.platform_admin_revoked', $account);
            $this->info($account->username.($on ? ' is now a platform admin.' : ' is no longer a platform admin.'));

            return self::SUCCESS;
        });
    }
}
