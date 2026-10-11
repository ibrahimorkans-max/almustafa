<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Claims (with append-only history) and pricing (price lists with append-only, versioned rates;
 * markup settings).
 */
return new class extends Migration
{
    private const TABLES = ['claims', 'claim_events', 'price_lists', 'price_list_items', 'markup_settings'];

    private const APPEND_ONLY = ['claim_events', 'price_list_items'];

    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->string('document_type', 16);             // sales_bill | purchase_bill
            $table->uuid('document_id');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->foreignUuid('transporter_party_id')->nullable()->constrained('parties');
            $table->string('type', 20);                       // weight_shortage | quality | damage | other
            $table->text('description');
            $table->unsignedBigInteger('claimed_paise');
            $table->unsignedBigInteger('settled_paise')->nullable();
            $table->json('evidence')->nullable();             // list of stored-file sha256
            $table->string('status', 12)->default('open');   // open | approved | settled | rejected | withdrawn
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['document_type', 'document_id']);
        });

        Schema::create('claim_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('claim_id')->constrained('claims');
            $table->string('event', 20);                     // raised | note | evidence | approved | rejected | settled | withdrawn
            $table->json('data')->nullable();
            $table->uuid('actor_login_account_id')->nullable();
            $table->uuid('actor_person_id')->nullable();
            $table->dateTime('created_at', 3);
            $table->index('claim_id');
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('name', 120);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('price_list_id')->constrained('price_lists');
            $table->foreignUuid('product_id')->constrained('products');
            $table->unsignedBigInteger('rate_paise_per_maund');
            $table->uuid('supersedes_id')->nullable();
            $table->uuid('approval_request_id')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['price_list_id', 'product_id']);
        });

        Schema::create('markup_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('category', 20);                  // product category
            $table->unsignedInteger('markup_bp');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'category']);
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
        foreach (['markup_settings', 'price_list_items', 'price_lists', 'claim_events', 'claims'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
