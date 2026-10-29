<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Banking\BankEmailImport;
use Illuminate\Console\Command;

/** Reads every connected mailbox whose sync interval has passed (scheduled every 5 minutes). */
final class BankEmailSyncCommand extends Command
{
    protected $signature = 'bank-email:sync';

    protected $description = 'Read bank notification e-mails from the connected mailboxes that are due';

    public function handle(BankEmailImport $import): int
    {
        $this->info($import->syncDue().' mailbox(es) read.');

        return self::SUCCESS;
    }
}
