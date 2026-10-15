<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Domain\Alerts\AlertService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Alert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * System-level alerts (failed backup or scheduled task, failed-login burst). They concern every
 * firm on the installation, so one is raised in each active entity for holders of 'audit.verify'
 * (Owners and Super Admins). The same open alert is not repeated within an hour.
 */
final class OpsAlerts
{
    public const PERMISSION = 'audit.verify';

    public function __construct(
        private readonly AlertService $alerts,
        private readonly TenantContext $tenant,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function raise(string $type, string $severity, string $title, ?string $body, array $data = []): int
    {
        $raised = 0;
        try {
            $entities = $this->tenant->runAsSystem(fn () => DB::table('entities')->where('status', 'active')->pluck('id')->all());
            foreach ($entities as $entityId) {
                $this->tenant->runInEntity((string) $entityId, function () use ($type, $severity, $title, $body, $data, &$raised): void {
                    $recent = Alert::query()->where('type', $type)->where('status', 'open')
                        ->where('created_at', '>=', now()->subHour()->utc())->exists();
                    if (! $recent) {
                        $this->alerts->raise($type, $severity, $title, $body, null, $data, self::PERMISSION);
                        $raised++;
                    }
                });
            }
        } catch (Throwable $e) {
            // Alerting must never take the failing job down with it; the log still has it.
            Log::critical('Could not raise ops alert', ['type' => $type, 'title' => $title, 'error' => $e->getMessage()]);
        }

        return $raised;
    }

    /** Failed logins across the whole installation in the last 15 minutes (password guessing). */
    public function checkFailedLoginBurst(): ?int
    {
        $count = $this->tenant->runAsSystem(fn () => DB::table('audit_log')->where('action', 'auth.login.failed')
            ->where('occurred_at', '>=', now()->subMinutes(15)->utc())->count());
        if ($count < (int) config('ricemill.ops.failed_login_burst')) {
            return null;
        }
        $this->raise('failed_login_burst', 'critical', "{$count} failed sign-ins in 15 minutes",
            'Someone may be guessing passwords. Check the audit log (auth.login.failed) for the IP addresses and user names.',
            ['count' => $count]);

        return $count;
    }
}
