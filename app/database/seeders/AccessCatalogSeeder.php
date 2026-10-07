<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\AccessCatalogSync;
use Illuminate\Database\Seeder;

/** Reference data required in every environment (also used by the test suite). */
class AccessCatalogSeeder extends Seeder
{
    public function run(AccessCatalogSync $sync): void
    {
        $sync->sync();
    }
}
