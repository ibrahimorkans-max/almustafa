<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounts\Books;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class BooksVerifyCommand extends Command
{
    protected $signature = 'books:verify';

    protected $description = 'Check that every voucher balances (debits = credits = voucher total)';

    public function handle(Books $books, TenantContext $tenant): int
    {
        $bad = $tenant->runAsSystem(fn () => $books->verify());

        if ($bad === []) {
            $this->info('All vouchers balance.');

            return self::SUCCESS;
        }

        $this->table(['voucher', 'debits', 'credits', 'total'], $bad);
        $this->error('Books verification FAILED.');

        return self::FAILURE;
    }
}
