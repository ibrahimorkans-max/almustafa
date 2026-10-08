<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

final class BackupRunCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Encrypted backup of the database and file store (off-site copy and retention when configured)';

    public function handle(BackupService $backups): int
    {
        try {
            $r = $backups->run();
        } catch (Throwable $e) {
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('%s · %s MB · sha256 %s · off-site: %s', $r['file'], number_format($r['size_bytes'] / 1048576, 1), $r['sha256'], $r['offsite'] ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
