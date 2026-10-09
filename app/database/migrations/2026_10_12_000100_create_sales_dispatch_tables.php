<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales and dispatch: dispatch orders, sales tax invoices, receipt allocations, and the gate
 * hardware log (number-plate reads, barrier decisions). The hardware log is append-only.
 */
return new class extends Migration
{
    private const TABLES = ['dispatch_orders', 'sales_bills', 'receipt_allocations', 'gate_device_events'];

    private const APPEND_ONLY = ['gate_device_events'];

    public function up(): void
    {
        Schema::create('dispatch_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('sauda_id')->constrained('saudas');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('godown_id')->constrained('godowns');
            $table->decimal('quantity_kg', 15, 3);
            $table->unsignedInteger('bag_count')->nullable();
            $table->decimal('bag_weight_kg', 8, 3)->nullable();
            $table->unsignedBigInteger('estimated_value_paise');   // incl. sales tax, for the credit check
            // pending_credit | open | loading | dispatched | cancelled
            $table->string('status', 16)->default('open');
            $table->uuid('credit_override_request_id')->nullable();
            $table->foreignUuid('gate_entry_id')->nullable()->constrained('gate_entries');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'status']);
        });

        Schema::table('gate_entries', function (Blueprint $table) {
            $table->foreignUuid('dispatch_order_id')->nullable()->constrained('dispatch_orders');
        });

        Schema::create('sales_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40)->nullable();        // invoice number, assigned when posted
            $table->foreignUuid('gate_entry_id')->constrained('gate_entries');
            $table->foreignUuid('dispatch_order_id')->constrained('dispatch_orders');
            $table->foreignUuid('sauda_id')->constrained('saudas');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->foreignUuid('product_id')->constrained('products');
            $table->date('bill_date');
            $table->date('due_date');
            $table->decimal('quantity_kg', 15, 3);
            $table->unsignedBigInteger('rate_paise_per_maund');
            // sales_tax = the mill has an STRN (Sales Tax Invoice); commercial = plain invoice, no tax lines.
            $table->string('invoice_type', 12)->default('commercial');
            $table->string('pct_code', 12)->nullable();
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->boolean('tax_exempt')->default(false);
            $table->unsignedInteger('further_tax_rate_bp')->default(0);   // s. 3(1A): supply to an unregistered buyer
            $table->string('seller_strn', 13)->nullable();
            $table->string('buyer_strn', 13)->nullable();
            $table->string('buyer_ntn', 13)->nullable();
            $table->unsignedBigInteger('taxable_paise');                  // value excluding sales tax
            $table->unsignedBigInteger('sales_tax_paise')->default(0);
            $table->unsignedBigInteger('further_tax_paise')->default(0);
            $table->bigInteger('round_off_paise')->default(0);
            $table->unsignedBigInteger('total_paise');
            $table->string('status', 12)->default('draft'); // draft | posted | cancelled
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->json('snapshot');
            $table->text('note')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique('gate_entry_id');
            $table->index(['entity_id', 'status']);
        });

        Schema::create('receipt_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('voucher_id')->constrained('vouchers');
            $table->foreignUuid('sales_bill_id')->constrained('sales_bills');
            $table->unsignedBigInteger('amount_paise');
            $table->timestamps();
        });

        Schema::create('gate_device_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('gate_entry_id')->nullable()->constrained('gate_entries');
            $table->uuid('device_id')->nullable();
            // plate_read | barrier_opened | barrier_refused | barrier_manual
            $table->string('kind', 20);
            $table->string('side', 4)->nullable();            // in | out
            $table->string('plate', 20)->nullable();
            $table->unsignedSmallInteger('confidence_bp')->nullable();
            $table->char('photo_sha256', 64)->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('data')->nullable();
            $table->uuid('actor_login_account_id')->nullable();
            $table->dateTime('occurred_at', 3);
            $table->dateTime('created_at', 3);
            $table->index(['gate_entry_id', 'kind']);
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
            $table->dropConstrainedForeignId('dispatch_order_id');
        });
        foreach (['gate_device_events', 'receipt_allocations', 'sales_bills', 'dispatch_orders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
