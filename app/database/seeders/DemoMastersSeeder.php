<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\LedgerAccount;
use App\Models\Entity;
use App\Models\Gate\DeductionRule;
use App\Models\Gate\Scale;
use App\Models\Gate\ScaleCertificate;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\Shift;
use App\Models\Hr\Site;
use App\Models\LoginAccount;
use App\Models\Masters\Brand;
use App\Models\Masters\Driver;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Vehicle;
use App\Models\Person;
use App\Models\Production\OutturnStandard;
use App\Support\Values\PakistanIds;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Typical master data for a rice mill in South Punjab (demo only; names are made up and identifiers are
 * valid in format only). Tax rates and PCT codes are examples for the demo: the mill's tax adviser sets
 * the real ones.
 */
class DemoMastersSeeder extends Seeder
{
    public function run(TenantContext $tenant): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoMastersSeeder must not run in production.');
        }

        $entities = $tenant->runAsSystem(fn () => Entity::query()->where('code', 'AMR')->get());
        foreach ($entities as $entity) {
            $tenant->runAsSystem(fn () => $this->seedEntity($entity), $entity->id);
        }
    }

    private function seedEntity(Entity $entity): void
    {
        if (Product::query()->exists()) {
            return; // already seeded
        }

        $exempt = TaxRate::query()->create(['name' => 'Exempt (demo: unbranded rice and paddy)', 'sales_tax_rate_bp' => 0, 'exempt' => true]);
        $standard = TaxRate::query()->create(['name' => 'Standard 18% (demo)', 'sales_tax_rate_bp' => 1800]);

        $brand = Brand::query()->create(['code' => 'ALMUSTAFA', 'name' => 'Al Mustafa Basmati']);

        $products = [
            ['PDY-SUPER', 'Paddy Super Basmati', 'paddy', 'Super Basmati', '1006.1000', $exempt, null, 'maund', null],
            ['PDY-1121', 'Paddy 1121 Kainat', 'paddy', '1121 Kainat', '1006.1000', $exempt, null, 'maund', null],
            ['PDY-IRRI6', 'Paddy IRRI-6', 'paddy', 'IRRI-6', '1006.1000', $exempt, null, 'maund', null],
            ['RCE-SKB-25', 'Super Kernel Basmati 25 kg (Al Mustafa)', 'rice', 'Super Basmati', '1006.3000', $standard, $brand, 'bag', '25'],
            ['RCE-1121-SELLA', '1121 Sella Basmati', 'rice', '1121 Kainat', '1006.3000', $exempt, null, 'maund', null],
            ['RCE-IRRI6', 'IRRI-6 white rice', 'rice', 'IRRI-6', '1006.3000', $exempt, null, 'maund', null],
            ['BYP-TOTA', 'Broken rice (tota)', 'by_product', null, '1006.4000', $exempt, null, 'maund', null],
            ['BYP-BRAN', 'Rice bran (phakki)', 'by_product', null, '2302.4000', $standard, null, 'maund', null],
            ['BYP-HUSK', 'Rice husk (pateeri)', 'by_product', null, '1213.0000', $standard, null, 'ton', null],
            ['PKG-PP25', 'PP bag 25 kg', 'packing', null, '6305.3300', $standard, null, 'kg', null],
        ];
        foreach ($products as [$code, $name, $category, $variety, $pct, $tax, $brandModel, $unit, $bag]) {
            Product::query()->create([
                'code' => $code, 'name' => $name, 'category' => $category, 'variety' => $variety, 'pct_code' => $pct,
                'tax_rate_id' => $tax->id, 'brand_id' => $brandModel?->id, 'sale_unit' => $unit, 'bag_weight_kg' => $bag,
            ]);
        }

        // Weighbridge with a valid verification certificate, and typical paddy deduction rules.
        $scale = Scale::query()->create(['code' => 'WB1', 'name' => 'Main weighbridge (60 t)', 'capacity_kg' => '60000', 'resolution_kg' => '10']);
        ScaleCertificate::query()->create([
            'scale_id' => $scale->id, 'type' => ScaleCertificate::TYPE_STAMPING, 'certificate_no' => 'WM/LDN/DEMO-'.$entity->code,
            'issued_by' => 'Weights & Measures, District Lodhran (demo)', 'issued_on' => now()->subMonths(3)->toDateString(), 'valid_until' => now()->addMonths(9)->toDateString(),
        ]);
        foreach (Product::query()->where('category', 'paddy')->get() as $paddy) {
            DeductionRule::query()->create(['product_id' => $paddy->id, 'parameter' => 'moisture', 'method' => 'excess_weight', 'free_limit_bp' => 1400, 'factor' => 10000, 'reject_above_bp' => 2200]);
            // Rs 10 per maund for every full 1 % of foreign matter above 2 %.
            DeductionRule::query()->create(['product_id' => $paddy->id, 'parameter' => 'foreign_matter', 'method' => 'rate_cut', 'free_limit_bp' => 200, 'factor' => 1000]);
            DeductionRule::query()->create(['product_id' => $paddy->id, 'parameter' => 'bags', 'method' => 'per_bag', 'factor' => 600]);
        }

        foreach ([['G1', 'Main godown', 'godown', '2500000'], ['S1', 'Paddy silo 1', 'silo', '1000000'], ['Y1', 'Open yard (phar)', 'yard', null]] as [$code, $name, $type, $cap]) {
            Godown::query()->create(['code' => $code, 'name' => $name, 'type' => $type, 'capacity_kg' => $cap]);
        }

        // Accounts: a mill bank account and by-product realisable values (demo figures).
        LedgerAccount::query()->create([
            'entity_id' => $entity->id, 'group_id' => app(ChartOfAccounts::class)->group('BANK_ACCOUNTS')->id,
            'code' => 'BANK-MAIN', 'name' => 'Meezan Bank current account (demo)', 'kind' => 'bank',
            'bank_account_number' => PakistanIds::ibanFor('MEZN', '0'.$this->digits($entity->code, 15)), 'bank_name' => 'Meezan Bank, Lodhran (demo)',
        ]);
        foreach (['BYP-HUSK' => 900, 'BYP-BRAN' => 6500, 'BYP-TOTA' => 9000] as $code => $paisaPerKg) {
            Product::query()->where('code', $code)->first()?->forceFill(['valuation_paise_per_kg' => $paisaPerKg])->save();
        }

        // Indicative outturn per paddy (demo values; each mill sets its own): head rice, broken, bran, husk.
        $byCode = Product::query()->pluck('id', 'code');
        foreach (['PDY-SUPER' => ['RCE-SKB-25', 6200], 'PDY-1121' => ['RCE-1121-SELLA', 6400], 'PDY-IRRI6' => ['RCE-IRRI6', 6700]] as $paddyCode => [$riceCode, $riceBp]) {
            foreach ([[$riceCode, $riceBp, 200], ['BYP-TOTA', 600, 200], ['BYP-BRAN', 700, 150], ['BYP-HUSK', 2100, 200]] as [$outCode, $bp, $tolerance]) {
                OutturnStandard::query()->create([
                    'paddy_product_id' => $byCode[$paddyCode], 'output_product_id' => $byCode[$outCode], 'expected_bp' => $bp, 'tolerance_bp' => $tolerance,
                ]);
            }
        }

        $p = $entity->code;
        $farmer = Party::query()->create([
            'code' => "{$p}-F001", 'name' => 'Muhammad Aslam (demo grower)', 'is_farmer' => true, 'is_vendor' => true, 'city' => 'Lodhran',
            'province' => 'Punjab', 'phone' => '0301-1234501', 'cnic' => '3620312345671', 'atl_status' => 'unknown', 'wht_exempt' => true,
        ]);
        $arhti = Party::query()->create([
            'code' => "{$p}-V001", 'name' => 'Haji Riaz & Sons, Ghalla Mandi (demo arhti)', 'is_vendor' => true, 'city' => 'Lodhran', 'province' => 'Punjab',
            'phone' => '0300-7654321', 'ntn' => '12345678', 'atl_status' => 'active', 'atl_checked_on' => now()->subDays(10)->toDateString(),
        ]);
        Party::query()->create([
            'code' => "{$p}-C001", 'name' => 'Indus Rice Exporters (demo)', 'is_customer' => true, 'city' => 'Karachi', 'province' => 'Sindh',
            'address' => 'Plot 12, Korangi Industrial Area', 'ntn' => '23456789', 'strn' => '3277876543210', 'atl_status' => 'active',
            'credit_limit_paise' => 5_000_000_00, 'credit_days' => 30,
        ]);
        Party::query()->create([
            'code' => "{$p}-C002", 'name' => 'Multan Kiryana Store (demo)', 'is_customer' => true, 'city' => 'Multan', 'province' => 'Punjab',
            'phone' => '0333-1112233', 'cnic' => '3630298765433', 'atl_status' => 'inactive', 'credit_limit_paise' => 500_000_00, 'credit_days' => 15,
        ]);
        Party::query()->create(['code' => "{$p}-B001", 'name' => 'Shahid Iqbal (demo broker)', 'is_broker' => true, 'city' => 'Multan', 'province' => 'Punjab', 'phone' => '0345-1234567']);
        $transporter = Party::query()->create(['code' => "{$p}-T001", 'name' => 'Lodhran Goods Transport (demo)', 'is_transporter' => true, 'city' => 'Lodhran', 'province' => 'Punjab']);

        $driver = Driver::query()->create(['name' => 'Muhammad Akram (demo)', 'phone' => '0302-9876543', 'licence_no' => 'LDN-2019-12345', 'licence_valid_until' => now()->addYears(3)->toDateString(), 'transporter_party_id' => $transporter->id]);
        Vehicle::query()->create(['registration_no' => "MNA-21-{$this->digits($p, 4)}", 'vehicle_type' => 'truck', 'owner_party_id' => $transporter->id, 'default_driver_id' => $driver->id, 'capacity_kg' => '25000']);
        Vehicle::query()->create(['registration_no' => "LRN-{$this->digits($p.'t', 4)}", 'vehicle_type' => 'tractor', 'owner_party_id' => $farmer->id]);
        Vehicle::query()->create(['registration_no' => "MNB-22-{$this->digits($p.'b', 4)}", 'vehicle_type' => 'truck', 'owner_party_id' => $arhti->id, 'is_blocked' => true, 'blocked_reason' => 'Demo: weight dispute pending']);

        $this->seedHr($entity);
    }

    /** The mill site for attendance (Adda Permat, Shujabad Road) and a few demo employees on the general shift. */
    private function seedHr(Entity $entity): void
    {
        Site::query()->create(['code' => 'MILL', 'name' => 'Mill — Adda Permat, Shujabad Road', 'kind' => 'mill',
            'latitude' => '29.5640000', 'longitude' => '71.6280000', 'radius_m' => 300]);

        // Demo accounts must change their password before they may act, so the demo employees are written
        // directly here (as EmployeeService would: next code, encrypted identifiers, audited).
        $shift = Shift::query()->where('code', 'GEN')->value('id');
        $dept = fn (string $code) => Department::query()->where('code', $code)->value('id');
        $worker = LoginAccount::query()->where('entity_id', $entity->id)->where('username', 'amr.employee')->first();
        $people = [
            ['full_name' => null, 'person_id' => $worker?->person_id, 'department_id' => $dept('PROD'), 'cnic' => '3620311111111', 'phone' => '0300-1111111'],
            ['full_name' => 'Ghulam Rasool (demo)', 'department_id' => $dept('GATE'), 'cnic' => '3620322222223', 'phone' => '0300-2222222'],
            ['full_name' => 'Nazir Ahmad (demo)', 'department_id' => $dept('MAINT'), 'cnic' => '3620333333335', 'phone' => '0300-3333333'],
        ];
        foreach ($people as $p) {
            if (($p['person_id'] ?? null) === null && $p['full_name'] === null) {
                continue;
            }
            DB::transaction(function () use ($p, $shift): void {
                $personId = $p['person_id'] ?? Person::query()->create(['full_name' => $p['full_name'], 'phone' => PakistanIds::normalizeMobile($p['phone'])])->id;
                $employee = new Employee([
                    'department_id' => $p['department_id'], 'employment_type' => 'permanent', 'cnic' => $p['cnic'], 'shift_id' => $shift,
                    'joining_date' => now()->subYear()->startOfMonth()->toDateString(), 'address' => 'Lodhran (demo)', 'date_of_birth' => '1990-01-15',
                ]);
                $employee->person_id = $personId;
                $employee->code = app(DocumentNumbers::class)->nextCode(DocumentNumbers::EMPLOYEE);
                $employee->status = Employee::ACTIVE;
                $employee->save();
            });
        }
    }

    /** Stable demo digits from a seed string. */
    private function digits(string $seed, int $length): string
    {
        return substr(str_pad((string) crc32($seed), $length, '7', STR_PAD_LEFT), -$length);
    }
}
