<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\LedgerAccount;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Production\OutturnStandard;
use App\Support\Values\PakistanIds;
use Database\Seeders\DemoMastersSeeder;
use Tests\Support\Make;

it('seeds demo masters, outturn standards and a mill bank account for the demo mill', function () {
    $entity = Make::entity('AMR');
    $this->seed(DemoMastersSeeder::class);

    app(TenantContext::class)->runInEntity($entity->id, function () {
        expect(OutturnStandard::query()->count())->toBe(12)
            ->and(PakistanIds::isValidIban((string) LedgerAccount::query()->where('kind', 'bank')->sole()->bank_account_number))->toBeTrue()
            ->and(Product::query()->where('code', 'BYP-BRAN')->value('valuation_paise_per_kg'))->toBe(6500)
            ->and(Product::query()->where('code', 'PDY-SUPER')->value('sale_unit'))->toBe('maund')
            ->and(Party::query()->where('code', 'AMR-C001')->sole()->isSalesTaxRegistered())->toBeTrue();
    });
});
