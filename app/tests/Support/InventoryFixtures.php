<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Inventory\StockLedger;
use App\Models\Entity;
use App\Models\Inventory\StockEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\OutturnStandard;
use App\Support\Values\Weight;

/**
 * Gate fixtures plus what milling needs: rice and by-products, a paddy silo and a finished-goods
 * godown, outturn standards for the paddy, and staff on both sides of maker-checker.
 */
final class InventoryFixtures
{
    public GateFixtures $gate;

    public Product $paddy;

    public Product $rice;

    public Product $broken;

    public Product $bran;

    public Product $husk;

    public Godown $silo;

    public Godown $store;

    public LoginAccount $supervisor;

    public LoginAccount $supervisor2;

    public LoginAccount $storeManager;

    public function __construct(public Entity $entity)
    {
        $this->gate = new GateFixtures($entity);
        $this->paddy = $this->gate->paddy;
        $this->gate->as($this->gate->owner);

        $product = fn (string $code, string $name, string $category) => Product::query()->create(['code' => $code, 'name' => $name, 'category' => $category, 'sale_unit' => 'kg']);
        $this->rice = $product('RICE-1121', 'Rice 1121 (head)', 'rice');
        $this->broken = $product('BRK-1121', 'Broken rice', 'by_product');
        $this->bran = $product('BRAN', 'Rice bran', 'by_product');
        $this->husk = $product('HUSK', 'Husk', 'by_product');

        $this->silo = Godown::query()->create(['code' => 'S1', 'name' => 'Paddy silo 1', 'type' => 'silo']);
        $this->store = Godown::query()->create(['code' => 'G1', 'name' => 'Finished goods', 'type' => 'godown']);

        foreach ([[$this->rice, 6500, 200], [$this->broken, 500, 200], [$this->bran, 700, 150], [$this->husk, 2000, 200]] as [$out, $bp, $tol]) {
            OutturnStandard::query()->create([
                'paddy_product_id' => $this->paddy->id, 'output_product_id' => $out->id, 'expected_bp' => $bp, 'tolerance_bp' => $tol,
            ]);
        }

        $this->supervisor = Make::account($entity, 'SUPERVISOR');
        $this->supervisor2 = Make::account($entity, 'SUPERVISOR');
        $this->storeManager = Make::account($entity, 'INVENTORY_MANAGER');
        Make::reset();
    }

    public function as(LoginAccount $account): void
    {
        $this->gate->as($account);
    }

    /** Opening stock booked straight on the ledger (as if received earlier). */
    public function stock(Product $product, Godown $godown, string $kg): StockEntry
    {
        return app(StockLedger::class)->post($product, $godown, Weight::kg($kg), StockEntry::RECEIPT, ['note' => 'opening']);
    }
}
