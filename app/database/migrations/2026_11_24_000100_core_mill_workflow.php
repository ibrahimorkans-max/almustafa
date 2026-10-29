<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The core mill workflow (Phase 2):
 *  - paddy varieties, a master of their own (PV-1121-0001), chosen on products, saudas, gate entries
 *    and paddy stock entries;
 *  - the gate token as one continuous number per mill (000001, 000002 …), never reset; tokens issued
 *    before keep the day's number printed on them (`token_no`);
 *  - With Jack / Without Jack, and the party name as typed at the gate (no party record needed);
 *  - deduction rules with a name, code, variety, effective date and the fixed-weight and
 *    percentage-of-net types;
 *  - paddy stock entries: receipts booked by hand or against a gate token, never twice;
 *  - a permanent code for each vehicle (VEH-000001); existing vehicles are numbered in the order added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paddy_varieties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('name_key', 120);          // lower case, single spaces: one "Super Basmati" per mill
            $table->string('category', 20)->default('basmati'); // basmati | non_basmati | hybrid | other
            $table->text('description')->nullable();
            $table->string('unit', 10)->default('kg'); // kg | maund | bag
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
            $table->unique(['entity_id', 'name_key']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('paddy_variety_id')->nullable()->constrained('paddy_varieties');
        });
        Schema::table('saudas', function (Blueprint $table) {
            $table->foreignUuid('paddy_variety_id')->nullable()->constrained('paddy_varieties');
        });
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('token_serial')->nullable();
            $table->string('jack_type', 16)->nullable();   // with_jack | without_jack
            $table->string('party_name', 160)->nullable(); // as typed, when no registered party was chosen
            $table->foreignUuid('paddy_variety_id')->nullable()->constrained('paddy_varieties');
            $table->unique(['entity_id', 'token_serial']);
            $table->index(['entity_id', 'jack_type']);
        });
        Schema::table('deduction_rules', function (Blueprint $table) {
            $table->string('name', 120)->nullable();
            $table->string('code', 30)->nullable();
            $table->foreignUuid('paddy_variety_id')->nullable()->constrained('paddy_varieties');
            $table->date('effective_from')->nullable();
            $table->string('condition_note', 200)->nullable();
            // Several weight rules per product (dust, bag weight …): one rule is known by its code instead.
            $table->dropUnique(['entity_id', 'product_id', 'parameter']);
            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'product_id', 'parameter']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('code', 30)->nullable();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('paddy_stock_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->date('entry_date');
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('paddy_variety_id')->nullable()->constrained('paddy_varieties');
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            $table->string('party_name', 160)->nullable();
            $table->foreignUuid('godown_id')->constrained('godowns');
            $table->string('registration_no', 24)->nullable();
            $table->foreignUuid('gate_entry_id')->nullable()->constrained('gate_entries');
            $table->foreignUuid('sauda_id')->nullable()->constrained('saudas');
            $table->unsignedInteger('bag_count')->nullable();
            $table->decimal('gross_kg', 15, 3);
            $table->decimal('tare_kg', 15, 3)->default(0);
            $table->decimal('net_kg', 15, 3);
            $table->decimal('deduction_kg', 15, 3)->default(0);
            $table->decimal('accepted_kg', 15, 3);
            $table->json('deduction_lines')->nullable();
            $table->string('deduction_override_reason', 300)->nullable();
            $table->bigInteger('rate_paise_per_maund')->nullable();
            $table->bigInteger('amount_paise')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 16)->default('draft'); // draft | posted | cancelled
            $table->uuid('stock_entry_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->unique(['entity_id', 'gate_entry_id']); // one stock entry per gate token
            $table->index(['entity_id', 'entry_date']);
        });

        TenantTables::secure(['paddy_varieties', 'paddy_stock_entries']);

        // Existing vehicles: VEH-000001 … per mill, in the order they were added; the counter goes on from there.
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            $counts = [];
            foreach (DB::table('vehicles')->orderBy('entity_id')->orderBy('created_at')->orderBy('id')->get(['id', 'entity_id']) as $v) {
                $n = $counts[$v->entity_id] = ($counts[$v->entity_id] ?? 0) + 1;
                DB::table('vehicles')->where('id', $v->id)->update(['code' => sprintf('VEH-%06d', $n)]);
            }
            foreach ($counts as $entityId => $n) {
                DB::table('document_sequences')->insertOrIgnore(['entity_id' => $entityId, 'series' => 'CODE-VEH', 'financial_year' => 'ALL', 'last_number' => $n]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paddy_stock_entries');
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'code']);
            $table->dropColumn('code');
        });
        Schema::table('deduction_rules', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'code']);
            $table->dropIndex(['entity_id', 'product_id', 'parameter']);
            $table->unique(['entity_id', 'product_id', 'parameter']);
            $table->dropConstrainedForeignId('paddy_variety_id');
            $table->dropColumn(['name', 'code', 'effective_from', 'condition_note']);
        });
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'token_serial']);
            $table->dropIndex(['entity_id', 'jack_type']);
            $table->dropConstrainedForeignId('paddy_variety_id');
            $table->dropColumn(['token_serial', 'jack_type', 'party_name']);
        });
        Schema::table('saudas', fn (Blueprint $table) => $table->dropConstrainedForeignId('paddy_variety_id'));
        Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('paddy_variety_id'));
        Schema::dropIfExists('paddy_varieties');
    }
};
