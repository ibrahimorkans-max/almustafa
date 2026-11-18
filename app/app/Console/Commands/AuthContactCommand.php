<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\FirstSignIn;
use App\Domain\Auth\NewNetwork;
use App\Domain\Auth\SignInApprovals;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Support\Values\PakistanIds;
use Illuminate\Console\Command;

/**
 * For the server's administrator: the e-mail every security code of a login goes to (a new network, a new device, a
 * forgotten password) and its mobile number — e.g. for the SaaS owner, who cannot sign in from a new network to set
 * them when it has none and nobody else can approve it. Shows how a sign-in from a new network or device would be
 * proven. A new e-mail is confirmed by its first code.
 */
final class AuthContactCommand extends Command
{
    protected $signature = 'auth:contact
        {username : The login}
        {--email= : The e-mail its security codes go to}
        {--phone= : Its mobile number, e.g. 0300-1234567}';

    protected $description = 'See or set the e-mail (where security codes go) and mobile of a login, e.g. the SaaS owner';

    public function handle(TenantContext $tenant, AuthContextBinder $binder, AuditLogger $audit): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        $account = $tenant->runAsSystem(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->where('username', $username)->first());
        if ($account === null) {
            $this->error("No login {$username}. Check the spelling (usernames are small letters, e.g. amr.owner).");

            return self::FAILURE;
        }
        $binder->bind($account);

        if (($email = mb_strtolower(trim((string) $this->option('email')))) !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
                $this->error("{$email} is not an e-mail address.");

                return self::FAILURE;
            }
            if ($email !== $account->email) {
                $tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($account->id)->toBase()
                    ->update(['email' => $email, 'email_verified_at' => null]));
                $audit->record('auth.security_email.set', $account, meta: ['email' => FirstSignIn::masked($email), 'by' => 'server command']);
            }
            $this->info("Security codes of {$account->username} now go to {$email} (confirmed by its first code).");
        }
        if (($phone = trim((string) $this->option('phone'))) !== '') {
            $normal = PakistanIds::normalizeMobile($phone);
            if ($normal === null || $account->person_id === null) {
                $this->error("{$phone} is not a mobile number (e.g. 0300-1234567).");

                return self::FAILURE;
            }
            $tenant->runAsSystem(fn () => Person::query()->withoutGlobalScopes()->whereKey($account->person_id)->toBase()
                ->update(['phone' => $normal, 'updated_at' => now()]));
            $audit->record('auth.security_phone.set', $account, meta: ['by' => 'server command']);
            $this->info("Mobile number of {$account->username} saved.");
        }

        $account = $tenant->runAsSystem(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->findOrFail($account->id));
        $network = app(NewNetwork::class);
        $approvers = app(SignInApprovals::class)->approvers($account)->pluck('username')->all();
        $this->newLine();
        $this->line("{$account->username} ({$account->role?->name}".($account->isPlatformAdmin() ? ', SaaS owner' : '').')');
        $this->line('E-mail for security codes: '.($account->email ? $account->email.($account->email_verified_at ? ' (confirmed)' : ' (confirmed by its first code)') : 'none'));
        $this->line('Mobile: '.($account->person?->phone ? (PakistanIds::formatMobile($account->person->phone) ?? $account->person->phone) : 'none'));
        $this->line('A new network or device is proven by: '.match (true) {
            $network->byEmail($account) => 'the code sent to that e-mail',
            $network->canAddEmail($account) => 'an e-mail the person gives on the check screen (after password and authenticator), then its code',
            $approvers !== [] => 'an approval (QR code + number) by '.implode(', ', $approvers),
            default => 'nothing yet — set an e-mail: php artisan auth:contact '.$account->username.' --email=...',
        });

        return self::SUCCESS;
    }
}
