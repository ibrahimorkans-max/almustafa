<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\AccessCatalogSync;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\RoleTemplates;
use Illuminate\Console\Command;

final class AccessSyncCommand extends Command
{
    protected $signature = 'access:sync {--export= : Also write the catalog as JSON to this path (for PWA / Flutter / agent)}';

    protected $description = 'Sync the permission catalog and the global Super Admin role from code';

    public function handle(AccessCatalogSync $sync): int
    {
        $sync->sync();
        $this->info(count(PermissionCatalog::codes()).' permissions synced.');

        if ($path = $this->option('export')) {
            file_put_contents($path, json_encode([
                'permissions' => PermissionCatalog::rows(),
                'roles' => RoleTemplates::all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Catalog exported to {$path}.");
        }

        return self::SUCCESS;
    }
}
