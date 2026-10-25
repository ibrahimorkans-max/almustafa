<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Archive\ArchiveService;
use App\Domain\Security\Lockdown;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

final class ArchiveRunCommand extends Command
{
    protected $signature = 'archive:run {--dry-run : Only report what would be archived} {--months= : Override the retention period}';

    protected $description = 'Archive closed records older than the retention period (never deletes)';

    public function handle(ArchiveService $archive, TenantContext $tenant): int
    {
        if (app(Lockdown::class)->activeLocks() !== []) {
            $this->warn('Skipped: a lockdown is active. Archiving runs again after it is lifted.');

            return self::SUCCESS;
        }
        // Mill by mill: each customer has its own retention period.
        $settings = app(SettingsService::class);
        $totals = [];
        foreach ($tenant->runAsSystem(fn () => Entity::query()->where('status', Entity::STATUS_ACTIVE)->orderBy('code')->get()) as $mill) {
            $months = (int) ($this->option('months') ?? $settings->archiveRetentionMonths($mill->id));
            $cutoff = now()->subMonths(max(1, $months));
            $counts = $tenant->runInEntity($mill->id, fn () => $archive->run($cutoff, (bool) $this->option('dry-run')));
            foreach ($counts as $type => $n) {
                $totals[$type] = ($totals[$type] ?? 0) + $n;
            }
            $this->line(sprintf('%s: closed before %s (%d months), %d records.', $mill->code, $cutoff->toDateString(), $months, array_sum($counts)));
        }

        $this->table(['type', $this->option('dry-run') ? 'would archive' : 'archived'], collect($totals)->map(fn ($n, $t) => [$t, $n])->values()->all());

        return self::SUCCESS;
    }
}
