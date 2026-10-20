<?php

declare(strict_types=1);

use App\Domain\Gate\LiveCapture;
use App\Domain\Gate\LiveWeight;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\LedgerAccount;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Weighment;
use App\Models\Gate\WeighmentCheck;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Models\Production\OutturnStandard;
use App\Support\Values\PakistanIds;
use Database\Seeders\DemoActivitySeeder;
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

it('seeds demo activity once: saudas, trucks at every gate stage and weights to check', function () {
    $entity = Make::entity('AMR');
    $this->seed(DemoMastersSeeder::class);
    $this->seed(DemoActivitySeeder::class);
    $this->seed(DemoActivitySeeder::class);   // a second run changes nothing

    app(TenantContext::class)->runInEntity($entity->id, function () {
        expect(Sauda::query()->count())->toBe(2)
            ->and(GateEntry::query()->pluck('status')->countBy()->all())->toEqual(['exited' => 3, 'arrived' => 1, 'unloaded' => 1, 'lab_accepted' => 1])
            ->and(Weighment::query()->count())->toBe(7)
            ->and(WeighmentCheck::query()->pluck('decision')->countBy()->all())->toEqual(['ok' => 3, 'query' => 1])
            ->and(app(LiveCapture::class)->waiting()->pluck('kind')->sort()->values()->all())->toBe(['gross', 'tare']);
    });
});

it('sends pretend weighbridge readings for the demo, and refuses without the demo data', function () {
    $this->artisan('ricemill:demo-live-weight', ['--seconds' => 1])->assertFailed();

    $entity = Make::entity('AMR');
    $this->seed(DemoMastersSeeder::class);
    $this->seed(DemoActivitySeeder::class);
    $this->artisan('ricemill:demo-live-weight', ['--seconds' => 2])->assertSuccessful();

    app(TenantContext::class)->runInEntity($entity->id, function () {
        expect(app(LiveWeight::class)->all()[0])->toMatchArray(['kg' => 0.0, 'live' => true]);
    });
});
