<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\SignInAttempts;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read only, for the server's administrator: the last sign-ins of a login (or of every login) with the precise
 * reason a refused one was refused, which the sign-in page never shows ("credentials do not match, or the account
 * is locked" covers all of them), the address and what was signing in (a browser or the gate agent).
 */
final class AuthSignInsCommand extends Command
{
    protected $signature = 'auth:sign-ins
        {username? : One login (default: every login)}
        {--limit=25 : How many, newest first}
        {--failed : Only refused ones}';

    protected $description = 'Show the last sign-ins with the real reason a refused one was refused';

    public function handle(TenantContext $tenant): int
    {
        $username = $this->argument('username') !== null ? mb_strtolower(trim((string) $this->argument('username'))) : null;
        $account = $username === null ? null
            : $tenant->runAsSystem(fn () => LoginAccount::query()->with('role')->where('username', $username)->first());

        $actions = $this->option('failed') ? ['auth.login.failed', 'auth.login.blocked'] : ['auth.login.failed', 'auth.login.blocked', 'auth.login.succeeded'];
        $rows = $tenant->runAsSystem(fn () => AuditLog::query()->whereIn('action', $actions)
            ->when($username !== null, fn ($q) => $q->where(fn ($w) => $w
                ->when($account !== null, fn ($a) => $a->where(fn ($s) => $s->where('subject_type', $account->getTable())->where('subject_id', $account->id)))
                ->orWhere('meta->username', $username)))
            ->orderByDesc('occurred_at')->limit(max(1, (int) $this->option('limit')))->get());

        if ($account !== null) {
            $this->line("{$account->username}: role {$account->role?->name}, status {$account->status}"
                .($account->isLocked() ? ', LOCKED'.($account->needs_admin_unlock ? ' (until unlocked: php artisan auth:unlock '.$account->username.')' : '') : '')
                .($account->is_machine ? ', a weighbridge PC login (only the gate agent signs in with it)' : ''));
        } elseif ($username !== null) {
            $this->warn("No login {$username}: sign-ins with that name are refused as an unknown username.");
        }
        if ($rows->isEmpty()) {
            $this->info('No sign-ins recorded.');

            return self::SUCCESS;
        }

        $this->table(['When (PKT)', 'Login', 'Result', 'Address', 'From'], $rows->map(function (AuditLog $r) use ($tenant): array {
            $reason = (string) ($r->meta['reason'] ?? '');

            return [
                $r->occurred_at?->timezone('Asia/Karachi')->format('d M H:i:s'),
                (string) ($r->meta['username'] ?? ($r->subject_id !== null ? $tenant->runAsSystem(fn () => LoginAccount::query()->whereKey($r->subject_id)->value('username')) : '-')),
                $r->action === 'auth.login.succeeded' ? 'OK' : (SignInAttempts::REASONS[$reason] ?? Str::headline($reason ?: $r->action)),
                (string) ($r->ip ?? ($r->meta['ip'] ?? '-')),
                self::from((string) $r->user_agent, (string) ($r->meta['channel'] ?? '')),
            ];
        })->all());

        return self::SUCCESS;
    }

    private static function from(string $agent, string $channel): string
    {
        return match (true) {
            $channel === 'api' && $agent === '', str_contains(strtolower($agent), 'python') => 'gate agent / app',
            $agent === '' => $channel !== '' ? $channel : '-',
            default => Str::limit($agent, 40),
        };
    }
}
