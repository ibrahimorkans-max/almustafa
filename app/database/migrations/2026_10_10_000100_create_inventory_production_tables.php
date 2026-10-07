<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory and production. Ledger and logs are append-only (corrections are reversing rows);
 * balances are a projection maintained under row locks and verifiable from the ledger.
 */
return new class extends Migration
{
    private const TABLES = [
        'stock_ledger', 'stock_balances', 'requisitions', 'production_batches', 'production_logs',
        'outturn_standards', 'stock_counts', 'alerts',
    ];

    private const APPEND_ONLY = ['stock_ledger', 'production_logs'];

    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('godown_id')->constrained('godowns');
            $table->decimal('quantity_kg', 15, 3);          // signed: + in, − out
            // receipt | production_issue | production_output | transfer_out | transfer_in | adjustment | reversal | dispatch
            $table->string('movement', 20);
            $table->string('source_type', 32)->nullable();   // gate_entry | production_log | requisition | stock_count
            $table->uuid('source_id')->nullable();
            $table->uuid('batch_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('occurred_at', 3);
            $table->dateTime('created_at', 3);
            $table->index(['product_id', 'godown_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('godown_id')->constrained('godowns');
            $table->decimal('quantity_kg', 15, 3)->default(0);
            $table->uuid('last_entry_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['product_id', 'godown_id']);
        });

        Schema::create('production_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('paddy_product_id')->constrained('products');
            $table->string('line', 60)->nullable();          // milling line / plant
            $table->string('status', 16)->default('open');   // open | closed
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('input_kg', 15, 3)->default(0);  // projection of input logs
            $table->decimal('output_kg', 15, 3)->default(0); // projection of output logs
            $table->json('yield_snapshot')->nullable();       // frozen at close
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
        });

        Schema::create('requisitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('from_godown_id')->constrained('godowns');
            $table->foreignUuid('to_godown_id')->nullable()->constrained('godowns');
            $table->foreignUuid('to_batch_id')->nullable()->constrained('production_batches');
            $table->decimal('quantity_kg', 15, 3);
            // requested | approved | issued | rejected | cancelled
            $table->string('status', 16)->default('requested');
            $table->text('purpose')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
        });

        Schema::create('production_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('batch_id')->constrained('production_batches');
            $table->string('kind', 8);                       // input | output
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('godown_id')->constrained('godowns');
            $table->decimal('quantity_kg', 15, 3);           // positive; reversals carry reverses_id
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->uuid('reverses_id')->nullable();
            $table->uuid('requisition_id')->nullable();
            $table->uuid('stock_ledger_id')->nullable();
            $table->text('note')->nullable();
            $table->uuid('device_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['batch_id', 'kind']);
        });

        Schema::create('outturn_standards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('paddy_product_id')->constrained('products');
            $table->foreignUuid('output_product_id')->constrained('products');
            $table->unsignedInteger('expected_bp');           // share of paddy input, 6700 = 67 %
            $table->unsignedInteger('tolerance_bp')->default(200);
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'paddy_product_id', 'output_product_id'], 'outturn_standards_paddy_output_unique');
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->date('count_date');
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('godown_id')->constrained('godowns');
            $table->decimal('system_kg', 15, 3);             // balance at the moment of counting
            $table->decimal('counted_kg', 15, 3);
            $table->decimal('difference_kg', 15, 3);
            $table->string('status', 16)->default('pending'); // pending | applied | rejected
            $table->uuid('approval_request_id')->nullable();
            $table->uuid('stock_ledger_id')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('type', 40);                      // outturn_drift | unaccounted_loss | shrinkage | …
            $table->string('severity', 10)->default('warning'); // info | warning | critical
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('data')->nullable();
            $table->string('status', 16)->default('open');   // open | acknowledged
            $table->uuid('acknowledged_by')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['entity_id', 'status']);
        });

        Schema::table('gate_entries', function (Blueprint $table) {
            // Where the lot was unloaded; the slip books the paddy receipt there.
            $table->foreignUuid('godown_id')->nullable()->constrained('godowns');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::unprepared("CREATE POLICY tenant_isolation ON {$table}
                    USING (app_rls_bypass() OR entity_id = app_entity_id())
                    WITH CHECK (app_rls_bypass() OR entity_id = app_entity_id())");
            }
            foreach (self::APPEND_ONLY as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update_delete BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION audit_log_reject_change()");
            }
        } else {
            foreach (self::APPEND_ONLY as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only (UPDATE rejected)'");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only (DELETE rejected)'");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            foreach (self::APPEND_ONLY as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }
        Schema::table('gate_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('godown_id');
        });
        foreach (['alerts', 'stock_counts', 'outturn_standards', 'production_logs', 'requisitions', 'production_batches', 'stock_balances', 'stock_ledger'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
