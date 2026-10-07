<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Masters\Brand;
use App\Models\Masters\Driver;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Vehicle;
use App\Support\Values\IndianIds;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Typical master data for a Haryana rice mill (demo only; identifiers are made up but valid in format).
 */
class DemoMastersSeeder extends Seeder
{
    public function run(TenantContext $tenant): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoMastersSeeder must not run in production.');
        }

        $entities = $tenant->runAsSystem(fn () => Entity::query()->whereIn('code', ['KNL', 'KKR'])->get());
        foreach ($entities as $entity) {
            $tenant->runAsSystem(fn () => $this->seedEntity($entity), $entity->id);
        }
    }

    private function seedEntity(Entity $entity): void
    {
        if (Product::query()->exists()) {
            return; // already seeded
        }

        $gst0 = TaxRate::query()->create(['name' => 'Nil (unbranded paddy)', 'gst_rate_bp' => 0]);
        $gst5 = TaxRate::query()->create(['name' => 'GST 5%', 'gst_rate_bp' => 500]);
        TaxRate::query()->create(['name' => 'GST 18%', 'gst_rate_bp' => 1800]);

        $brand = Brand::query()->create(['code' => 'SUNEHRI', 'name' => 'Sunehri Basmati (demo)']);

        $products = [
            ['PDY-1121', 'Paddy 1121', 'paddy', '1121', '1006', $gst0, null, 'quintal', null],
            ['PDY-PR126', 'Paddy PR-126', 'paddy', 'PR-126', '1006', $gst0, null, 'quintal', null],
            ['RCE-1121-25', 'Basmati 1121 Sella 25 kg', 'rice', '1121', '1006', $gst5, $brand, 'bag', '25'],
            ['RCE-PR126', 'Non-basmati PR-126 raw', 'rice', 'PR-126', '1006', $gst0, null, 'quintal', null],
            ['BYP-HUSK', 'Rice husk', 'by_product', null, '1213', $gst5, null, 'ton', null],
            ['BYP-BRAN', 'Rice bran', 'by_product', null, '2302', $gst0, null, 'quintal', null],
            ['BYP-BROKEN', 'Broken rice', 'by_product', null, '1006', $gst0, null, 'quintal', null],
            ['PKG-PP25', 'PP bag 25 kg', 'packing', null, '6305', $gst5, null, 'kg', null],
        ];
        foreach ($products as [$code, $name, $category, $variety, $hsn, $tax, $brandModel, $unit, $bag]) {
            Product::query()->create([
                'code' => $code, 'name' => $name, 'category' => $category, 'variety' => $variety, 'hsn_code' => $hsn,
                'tax_rate_id' => $tax->id, 'brand_id' => $brandModel?->id, 'sale_unit' => $unit, 'bag_weight_kg' => $bag,
            ]);
        }

        foreach ([['G1', 'Main godown', 'godown', '2500000'], ['S1', 'Paddy silo 1', 'silo', '1000000'], ['Y1', 'Open yard', 'yard', null]] as [$code, $name, $type, $cap]) {
            Godown::query()->create(['code' => $code, 'name' => $name, 'type' => $type, 'capacity_kg' => $cap]);
        }

        $prefix = $entity->code;
        $farmer = Party::query()->create(['code' => "{$prefix}-F001", 'name' => 'Ram Singh (demo farmer)', 'is_farmer' => true, 'is_vendor' => true, 'city' => 'Karnal', 'state_code' => '06', 'phone' => '9876500001']);
        $trader = Party::query()->create([
            'code' => "{$prefix}-V001", 'name' => 'Haryana Paddy Traders (demo)', 'is_vendor' => true, 'city' => 'Kaithal',
            'gst_registration_type' => 'regular', 'gstin' => $this->demoGstin('06', 'AAAFH1234', 'F'),
        ]);
        Party::query()->create([
            'code' => "{$prefix}-C001", 'name' => 'Delhi Rice House (demo)', 'is_customer' => true, 'city' => 'New Delhi',
            'gst_registration_type' => 'regular', 'gstin' => $this->demoGstin('07', 'AAACD1234', 'C'), 'credit_limit_paise' => 500_000_00, 'credit_days' => 30,
        ]);
        Party::query()->create(['code' => "{$prefix}-B001", 'name' => 'Suresh Kumar (demo broker)', 'is_broker' => true, 'city' => 'Karnal', 'phone' => '9876500002']);
        $transporter = Party::query()->create(['code' => "{$prefix}-T001", 'name' => 'Karnal Roadways (demo)', 'is_transporter' => true, 'city' => 'Karnal']);

        $driver = Driver::query()->create(['name' => 'Gurpreet (demo)', 'phone' => '9876500003', 'licence_no' => 'HR0520190001234', 'licence_valid_until' => now()->addYears(3)->toDateString(), 'transporter_party_id' => $transporter->id]);
        Vehicle::query()->create(['registration_no' => "HR05AB{$this->num($prefix, 1)}", 'vehicle_type' => 'truck', 'owner_party_id' => $transporter->id, 'default_driver_id' => $driver->id, 'capacity_kg' => '25000']);
        Vehicle::query()->create(['registration_no' => "HR05CD{$this->num($prefix, 2)}", 'vehicle_type' => 'tractor', 'owner_party_id' => $farmer->id]);
        Vehicle::query()->create(['registration_no' => "HR08EF{$this->num($prefix, 3)}", 'vehicle_type' => 'truck', 'owner_party_id' => $trader->id, 'is_blocked' => true, 'blocked_reason' => 'Demo: weight dispute pending']);
    }

    /** Builds a format-valid GSTIN (with correct check digit) from a state code and a PAN body. */
    private function demoGstin(string $state, string $panBody9, string $panLast): string
    {
        $pan = substr($panBody9.'000000000', 0, 9).$panLast;
        $first14 = $state.$pan.'1Z';

        return $first14.IndianIds::gstinCheckChar($first14);
    }

    private function num(string $prefix, int $n): string
    {
        return str_pad((string) ((crc32($prefix) % 9000) + $n), 4, '0', STR_PAD_LEFT);
    }
}
