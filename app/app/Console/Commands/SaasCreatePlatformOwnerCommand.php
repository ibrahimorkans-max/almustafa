<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The SaaS product's own account, apart from every customer: a company of its own (never billed, never
 * paused), a small office "mill" to hold its login, and an Owner login that is the platform admin. The
 * temporary password is written to a private file on the server, never printed; it must be changed at
 * first sign-in, and two-step sign-in set up.
 */
final class SaasCreatePlatformOwnerCommand extends Command
{
    protected $signature = 'saas:create-platform-owner
        {username=saas.owner : The new login}
        {--name=SaaS Owner : The person\'s name}
        {--company= : The product company (default: the product name)}
        {--revoke-others : Stop every other account being a platform admin}';

    protected $description = 'Create the SaaS product owner\'s own account (platform admin), apart from every customer';

    public function handle(TenantContext $tenant, CustomerOnboarding $onboarding, AuditLogger $audit): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        if (! preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
            $this->error('Use 3 to 64 small letters, numbers, dots, dashes or underscores for the username.');

            return self::FAILURE;
        }
        if ($tenant->runAsSystem(fn () => LoginAccount::query()->where('username', $username)->exists())) {
            $this->error("The username {$username} is taken.");

            return self::FAILURE;
        }

        $company = trim((string) ($this->option('company') ?: config('ricemill.saas.product_name')));
        $password = Str::password(16, symbols: false);
        $opened = $onboarding->open([
            'company' => $company, 'mill_name' => $company.' office', 'mill_code' => $this->officeCode($tenant),
            'owner_name' => (string) $this->option('name'), 'username' => $username, 'password' => $password,
            'status' => Customer::ACTIVE, 'must_change_password' => true,
            'notes' => 'The SaaS product\'s own company: holds the platform admin login. Not a paying customer.',
        ], 'platform');

        $tenant->runAsSystem(function () use ($opened, $audit): void {
            $opened['customer']->update(['plan_id' => null]);   // no plan: never invoiced, every module
            $opened['owner']->forceFill(['is_platform_admin' => true])->save();
            $audit->record('saas.platform_admin_granted', $opened['owner']);
        });

        if ($this->option('revoke-others')) {
            $others = $tenant->runAsSystem(fn () => LoginAccount::query()->where('is_platform_admin', true)
                ->whereKeyNot($opened['owner']->id)->get());
            foreach ($others as $other) {
                $tenant->runAsSystem(fn () => $other->forceFill(['is_platform_admin' => false])->save());
                $audit->record('saas.platform_admin_revoked', $other);
                $this->line("{$other->username} is no longer a platform admin.");
            }
        }

        $file = storage_path('app/private/saas-owner-login.txt');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, implode(PHP_EOL, [
            'SaaS product owner (platform admin) - keep private, delete after first sign-in',
            'Sign in: '.url('/admin/login'),
            'Username: '.$username,
            'Temporary password: '.$password,
            'At first sign-in you choose your own password, then set up two-step sign-in.',
        ]).PHP_EOL);

        $this->info("Created {$username}: platform admin of {$opened['customer']->code} {$company} (office {$opened['mill']->code}).");
        $this->line("The temporary password is in {$file}");

        return self::SUCCESS;
    }

    /** HQ, HQ2, … */
    private function officeCode(TenantContext $tenant): string
    {
        $code = 'HQ';
        for ($i = 2; $tenant->runAsSystem(fn () => Entity::query()->where('code', $code)->exists()); $i++) {
            $code = 'HQ'.$i;
        }

        return $code;
    }
}
