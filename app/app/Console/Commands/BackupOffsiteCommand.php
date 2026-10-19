<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Backup\BackupService;
use Illuminate\Console\Command;

/** Hourly: sends the backups the off-site disk is missing (they pile up while a mill server has no internet). */
final class BackupOffsiteCommand extends Command
{
    protected $signature = 'backup:offsite';

    protected $description = 'Copy local backups that are not off-site yet (after the internet was down)';

    public function handle(BackupService $backups): int
    {
        $copied = $backups->offsiteCatchUp();
        $this->info($copied > 0 ? "{$copied} backup(s) copied off-site." : 'Nothing to copy (all off-site, none configured, or still no internet).');

        return self::SUCCESS;
    }
}
