<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\ScaleCertificate;
use App\Models\LoginAccount;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Daily: reminds the people responsible when a weighbridge stamping / calibration certificate
 * expires in 30 or 7 days, or today (and every day after while still not renewed).
 */
final class ScaleCertificateRemindersCommand extends Command
{
    protected $signature = 'scales:certificate-reminders';

    protected $description = 'Notify about expiring weighbridge certificates';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $days = (array) config('ricemill.gate.certificate_reminder_days');
        $entities = $tenant->runAsSystem(fn () => Entity::query()->pluck('id'));

        foreach ($entities as $entityId) {
            $tenant->runAsSystem(function () use ($days, $audit): void {
                // Latest certificate per scale and type: an older expired one that was renewed is not news.
                $latest = ScaleCertificate::query()->with('scale')->orderByDesc('valid_until')->get()
                    ->unique(fn (ScaleCertificate $c) => $c->scale_id.'|'.$c->type);

                $due = $latest->filter(fn (ScaleCertificate $c) => in_array($c->daysLeft(), $days, true) || $c->daysLeft() < 0);
                if ($due->isEmpty()) {
                    return;
                }

                $recipients = LoginAccount::query()->with('role')->where('status', 'active')->get()
                    ->filter(fn (LoginAccount $a) => $a->hasPermission('weighment.override') || $a->hasPermission('master.manage'));

                foreach ($due as $certificate) {
                    $left = $certificate->daysLeft();
                    $title = $left < 0
                        ? "Weighbridge {$certificate->scale->code}: {$certificate->type} certificate EXPIRED"
                        : "Weighbridge {$certificate->scale->code}: {$certificate->type} certificate expires in {$left} day(s)";
                    Notification::make()->title($title)
                        ->body("Certificate {$certificate->certificate_no}, valid until {$certificate->valid_until->toDateString()}.")
                        ->{$left <= 0 ? 'danger' : 'warning'}()
                        ->sendToDatabase($recipients);
                    $audit->record('scale.certificate.reminder', $certificate, meta: ['days_left' => $left, 'recipients' => $recipients->count()]);
                    $this->line($title);
                }
            }, $entityId);
        }

        return self::SUCCESS;
    }
}
