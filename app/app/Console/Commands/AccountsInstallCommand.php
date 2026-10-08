<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

/** Installs the standard chart of accounts for entities created before phase 5 (idempotent). */
final class AccountsInstallCommand extends Command
{
    protected $signature = 'accounts:install';

    protected $description = 'Create the standard account groups and system ledgers for every entity that lacks them';

    public function handle(ChartOfAccounts $chart, TenantContext $tenant): int
    {
        foreach ($tenant->runAsSystem(fn () => Entity::query()->get()) as $entity) {
            $chart->install($entity->id);
            $this->line("{$entity->code}: chart of accounts ready");
        }

        return self::SUCCESS;
    }
}
