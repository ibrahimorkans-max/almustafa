<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

final class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify {file? : Backup file name (default: the latest)}';

    protected $description = 'Restore drill: restore a backup into the drill database and run the audit, books and stock verifiers';

    public function handle(BackupService $backups): int
    {
        try {
            $r = $backups->drill($this->argument('file'));
        } catch (Throwable $e) {
            $this->error('Restore drill FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Restore drill OK: {$r['file']} — audit chains intact, books and stock balance, {$r['files_checked']} files checked.");

        return self::SUCCESS;
    }
}
