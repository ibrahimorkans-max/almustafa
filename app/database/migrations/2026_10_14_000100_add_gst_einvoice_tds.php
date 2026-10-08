<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-invoice / e-way bill results on sales invoices (with an append-only history of every request
 * and response), TDS under s. 194Q on purchase bills, and a per-party 194Q exemption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_bills', function (Blueprint $table) {
            $table->string('irn', 64)->nullable();
            $table->string('irn_ack_no', 30)->nullable();
            $table->dateTime('irn_ack_at')->nullable();
            $table->text('irn_signed_qr')->nullable();
            $table->string('einvoice_status', 12)->nullable();   // generated | cancelled
            $table->string('eway_bill_no', 20)->nullable();
            $table->dateTime('eway_valid_until')->nullable();
        });

        Schema::create('einvoice_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('sales_bill_id')->constrained('sales_bills');
            $table->string('kind', 20);                       // irn_generated | irn_cancelled | ewb_generated | failed
            $table->string('provider', 30);
            $table->json('request');
            $table->json('response')->nullable();
            $table->uuid('actor_login_account_id')->nullable();
            $table->dateTime('created_at', 3);
            $table->index('sales_bill_id');
        });

        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->unsignedBigInteger('tds_paise')->default(0);        // s. 194Q, deducted from what the supplier is paid
            $table->unsignedInteger('tds_rate_bp')->default(0);
            $table->unsignedBigInteger('tds_base_paise')->default(0);   // part of this bill above the yearly threshold
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->boolean('tds_194q_exempt')->default(false);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE einvoice_events ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE einvoice_events FORCE ROW LEVEL SECURITY');
            DB::unprepared('CREATE POLICY tenant_isolation ON einvoice_events
                USING (app_rls_bypass() OR entity_id = app_entity_id())
                WITH CHECK (app_rls_bypass() OR entity_id = app_entity_id())');
            DB::unprepared('CREATE TRIGGER einvoice_events_no_update_delete BEFORE UPDATE OR DELETE ON einvoice_events
                FOR EACH ROW EXECUTE FUNCTION audit_log_reject_change()');
        } else {
            DB::unprepared("CREATE TRIGGER einvoice_events_no_update BEFORE UPDATE ON einvoice_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'einvoice_events is append-only (UPDATE rejected)'");
            DB::unprepared("CREATE TRIGGER einvoice_events_no_delete BEFORE DELETE ON einvoice_events FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'einvoice_events is append-only (DELETE rejected)'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS einvoice_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS einvoice_events_no_delete');
        }
        Schema::dropIfExists('einvoice_events');
        Schema::table('sales_bills', function (Blueprint $table) {
            $table->dropColumn(['irn', 'irn_ack_no', 'irn_ack_at', 'irn_signed_qr', 'einvoice_status', 'eway_bill_no', 'eway_valid_until']);
        });
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropColumn(['tds_paise', 'tds_rate_bp', 'tds_base_paise']);
        });
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('tds_194q_exempt');
        });
    }
};
