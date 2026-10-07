<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\StockLedger;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class StockVerifyCommand extends Command
{
    protected $signature = 'stock:verify';

    protected $description = 'Recompute every stock balance from the stock ledger and report mismatches';

    public function handle(StockLedger $stock, TenantContext $tenant): int
    {
        $mismatches = $tenant->runAsSystem(fn () => $stock->verify());

        if ($mismatches === []) {
            $this->info('Stock balances match the ledger.');

            return self::SUCCESS;
        }

        $this->table(['product_id', 'godown_id', 'balance_kg', 'ledger_kg'], $mismatches);
        $this->error('Stock balance verification FAILED.');

        return self::FAILURE;
    }
}
