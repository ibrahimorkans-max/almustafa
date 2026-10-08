<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\LedgerAccount;
use App\Models\Masters\Product;
use App\Models\Production\OutturnStandard;
use Database\Seeders\DemoMastersSeeder;
use Tests\Support\Make;

it('seeds demo masters, outturn standards and a mill bank account for the demo mills', function () {
    $entity = Make::entity('KNL');
    $this->seed(DemoMastersSeeder::class);

    app(TenantContext::class)->runInEntity($entity->id, function () {
        expect(OutturnStandard::query()->count())->toBe(8)
            ->and(LedgerAccount::query()->where('kind', 'bank')->sole()->bank_account_last4)->toHaveLength(4)
            ->and(Product::query()->where('code', 'BYP-BRAN')->value('valuation_paise_per_kg'))->toBe(1800);
    });
});
