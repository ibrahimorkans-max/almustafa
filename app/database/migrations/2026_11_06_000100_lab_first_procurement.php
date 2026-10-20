<?php

declare(strict_types=1);

use App\Domain\Gate\LabStandards;
use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lab-first procurement:
 *  - lab_parameters: the lab tests (English and Urdu names) with a standard and a grace limit, per mill
 *    and optionally per product;
 *  - lab_tests.results / evaluation: each test's result and how it read against its limits;
 *  - gate_price_versions: every price offered or negotiated for a vehicle, never overwritten.
 * Every existing mill gets the seven starting tests (LabStandards::DEFAULTS), to be set to its own limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_parameters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('product_id')->nullable()->constrained('products'); // null: every product
            $table->string('code', 30);
            $table->string('name_en', 80);
            $table->string('name_ur', 80);
            $table->string('unit', 10)->default('%');
            $table->unsignedInteger('standard_bp');   // 14.00 % = 1400
            $table->unsignedInteger('grace_bp');      // above this the lot is rejected automatically
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('status', 12)->default('active');
            $table->timestamps();
            $table->index(['entity_id', 'code']);
        });

        Schema::table('lab_tests', function (Blueprint $table) {
            $table->json('results')->nullable();      // code => basis points
            $table->json('evaluation')->nullable();   // each result against its limits, as read when recorded
        });

        Schema::create('gate_price_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('gate_entry_id')->constrained('gate_entries');
            $table->unsignedSmallInteger('version');
            $table->bigInteger('price_paise_per_maund');
            $table->string('kind', 12);               // offered | revised
            $table->text('reason')->nullable();       // why the price was lowered / raised
            $table->string('status', 12);             // proposed | accepted | rejected | superseded
            $table->uuid('proposed_by')->nullable();
            $table->timestamp('proposed_at');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->unique(['gate_entry_id', 'version']);
        });

        TenantTables::secure(['lab_parameters', 'gate_price_versions']);

        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            $sort = 0;
            $rows = [];
            foreach (DB::table('entities')->pluck('id') as $entityId) {
                $sort = 0;
                foreach (LabStandards::DEFAULTS as $code => [$en, $ur, $standard, $grace]) {
                    $rows[] = ['id' => (string) Str::uuid7(), 'entity_id' => $entityId, 'product_id' => null, 'code' => $code, 'name_en' => $en,
                        'name_ur' => $ur, 'unit' => '%', 'standard_bp' => (int) round($standard * 100), 'grace_bp' => (int) round($grace * 100),
                        'sort' => $sort += 10, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()];
                }
            }
            if ($rows !== []) {
                DB::table('lab_parameters')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_price_versions');
        Schema::table('lab_tests', function (Blueprint $table) {
            $table->dropColumn(['results', 'evaluation']);
        });
        Schema::dropIfExists('lab_parameters');
    }
};
