<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Alerts\AnomalyScanner;
use App\Domain\Security\Lockdown;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

final class AnomaliesScanCommand extends Command
{
    protected $signature = 'anomalies:scan {--hours=24 : How far back to look at new records}';

    protected $description = 'Run the anomaly rules for every mill and raise alerts (de-duplicated)';

    public function handle(TenantContext $tenant): int
    {
        $since = now()->subHours(max(1, (int) $this->option('hours')));
        foreach ($tenant->runAsSystem(fn () => Entity::query()->where('status', Entity::STATUS_ACTIVE)->get()) as $entity) {
            if (app(Lockdown::class)->isLocked($entity->id)) {
                $this->warn("{$entity->code}: skipped, locked by administrator");

                continue;
            }
            $counts = $tenant->runInEntity($entity->id, fn () => app(AnomalyScanner::class)->scan($since));
            $this->line(sprintf('%s: %d alert(s) %s', $entity->code, array_sum($counts), json_encode(array_filter($counts))));
        }

        return self::SUCCESS;
    }
}
