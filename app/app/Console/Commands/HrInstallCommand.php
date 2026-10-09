<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Hr\HrInstaller;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

final class HrInstallCommand extends Command
{
    protected $signature = 'hr:install';

    protected $description = 'Adds the default pay heads and departments to every entity (idempotent)';

    public function handle(TenantContext $tenant, HrInstaller $installer): int
    {
        foreach ($tenant->runAsSystem(fn () => Entity::query()->get()) as $entity) {
            $this->line("{$entity->code}: ".$installer->install($entity->id).' added');
        }

        return self::SUCCESS;
    }
}
