<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Backup\BackupCrypto;
use Illuminate\Console\Command;

final class BackupKeyCommand extends Command
{
    protected $signature = 'backup:key';

    protected $description = 'Print a new random backup key for RICEMILL_BACKUP_KEY (store it in the secret store AND somewhere safe off the server)';

    public function handle(): int
    {
        $this->line(BackupCrypto::newKey());
        $this->warn('Without this key the backups cannot be restored. Keep a copy off the server (e.g. sealed envelope / password manager).');

        return self::SUCCESS;
    }
}
