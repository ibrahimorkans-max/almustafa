<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Archive\ArchiveService;
use App\Domain\Security\Lockdown;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
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
        $months = (int) ($this->option('months') ?? app(SettingsService::class)->archiveRetentionMonths());
        $cutoff = now()->subMonths(max(1, $months));
        $counts = $tenant->runAsSystem(fn () => $archive->run($cutoff, (bool) $this->option('dry-run')));

        $this->table(['type', $this->option('dry-run') ? 'would archive' : 'archived'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info(sprintf('Closed before %s.', $cutoff->toDateString()));

        return self::SUCCESS;
    }
}
