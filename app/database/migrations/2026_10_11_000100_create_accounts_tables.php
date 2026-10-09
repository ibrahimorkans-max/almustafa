<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts. Vouchers and their lines are append-only (corrections are reversal vouchers); money is
 * integer paise. Purchase bills and payments are workflow records that end in a posted voucher.
 */
return new class extends Migration
{
    private const TABLES = [
        'account_groups', 'ledger_accounts', 'vouchers', 'voucher_lines', 'period_locks', 'purchase_bills',
        'payments', 'payment_allocations', 'bank_files', 'bank_statement_lines', 'product_costs',
    ];

    private const APPEND_ONLY = ['vouchers', 'voucher_lines', 'product_costs'];

    public function up(): void
    {
        Schema::create('account_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->uuid('parent_id')->nullable();
            $table->string('nature', 12);                    // asset | liability | income | expense
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });
        Schema::table('account_groups', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('account_groups');
        });

        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('group_id')->constrained('account_groups');
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('kind', 12)->default('general');  // general | party | bank | cash | system
            $table->string('system_key', 40)->nullable();     // purchase_paddy, brokerage, rate_cut, cash, round_off, …
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            // Mill bank accounts (kind = bank): number encrypted + blind index, shown masked.
            $table->text('bank_account_number')->nullable();
            $table->string('bank_account_last4', 4)->nullable();
            $table->string('bank_code', 4)->nullable();      // from the IBAN, e.g. MEZN
            $table->string('bank_name', 120)->nullable();
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
            $table->unique(['entity_id', 'system_key']);
            $table->unique(['entity_id', 'party_id']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('type', 16);                      // purchase | payment | receipt | journal | contra | debit_note | credit_note | opening
            $table->string('number', 40);
            $table->date('voucher_date');
            $table->text('narration')->nullable();
            $table->unsignedBigInteger('total_paise');       // Σ Dr = Σ Cr
            $table->string('source_type', 32)->nullable();   // purchase_bill | payment | …
            $table->uuid('source_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'voucher_date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('voucher_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('voucher_id')->constrained('vouchers');
            $table->unsignedSmallInteger('line_no');
            $table->foreignUuid('ledger_account_id')->constrained('ledger_accounts');
            $table->unsignedBigInteger('dr_paise')->default(0);
            $table->unsignedBigInteger('cr_paise')->default(0);
            $table->date('voucher_date');                    // denormalised for books and ageing
            $table->uuid('bill_id')->nullable();             // purchase bill this line creates or settles
            $table->string('memo', 255)->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['ledger_account_id', 'voucher_date']);
            $table->index('bill_id');
        });

        Schema::create('period_locks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('period', 7);                     // YYYY-MM
            $table->boolean('locked')->default(true);
            $table->uuid('locked_by')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'period']);
        });

        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40)->nullable();        // voucher number once posted
            $table->foreignUuid('gate_entry_id')->constrained('gate_entries');
            $table->foreignUuid('sauda_id')->constrained('saudas');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->foreignUuid('broker_party_id')->nullable()->constrained('parties');
            $table->foreignUuid('product_id')->constrained('products');
            $table->date('bill_date');
            $table->date('due_date');
            $table->decimal('payable_kg', 15, 3);
            $table->unsignedBigInteger('rate_paise_per_maund');
            $table->unsignedBigInteger('gross_paise');       // payable kg × rate
            $table->unsignedBigInteger('rate_cut_paise')->default(0);
            $table->unsignedBigInteger('brokerage_paise')->default(0);
            $table->bigInteger('round_off_paise')->default(0);
            $table->unsignedBigInteger('net_paise');         // owed to the party
            $table->string('status', 12)->default('draft');  // draft | posted | cancelled
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->json('snapshot');                         // slip + sauda terms the bill was computed from
            $table->text('note')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique('gate_entry_id');
            $table->index(['entity_id', 'status']);
        });

        Schema::create('bank_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('ledger_account_id')->constrained('ledger_accounts');
            $table->unsignedInteger('payment_count');
            $table->unsignedBigInteger('total_paise');
            $table->char('sha256', 64);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('party_id')->constrained('parties');
            $table->string('mode', 10);                      // ibft | rtgs | raast | cheque | pay_order | cash
            $table->foreignUuid('from_ledger_id')->constrained('ledger_accounts');
            $table->unsignedBigInteger('amount_paise');
            $table->date('payment_date');
            $table->foreignUuid('party_bank_account_id')->nullable()->constrained('party_bank_accounts');
            $table->json('payee')->nullable();                // frozen holder / bank code / last4 (number stays encrypted below)
            $table->text('payee_account_number')->nullable(); // encrypted
            $table->string('status', 20)->default('pending'); // pending | first_approved | approved | rejected | cancelled
            $table->uuid('approval_request_id')->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->foreignUuid('bank_file_id')->nullable()->constrained('bank_files');
            $table->string('reference', 60)->nullable();     // UTR / cheque no.
            $table->text('narration')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->foreignUuid('purchase_bill_id')->constrained('purchase_bills');
            $table->unsignedBigInteger('amount_paise');
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('ledger_account_id')->constrained('ledger_accounts');
            $table->date('txn_date');
            $table->string('description', 255);
            $table->string('reference', 80)->nullable();
            $table->unsignedBigInteger('debit_paise')->default(0);   // money out of the bank
            $table->unsignedBigInteger('credit_paise')->default(0);  // money in
            $table->char('fingerprint', 64);                  // de-duplicates re-imports
            $table->foreignUuid('voucher_line_id')->nullable()->constrained('voucher_lines');
            $table->string('match_method', 12)->nullable();   // auto | manual
            $table->uuid('matched_by')->nullable();
            $table->dateTime('matched_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['ledger_account_id', 'fingerprint']);
        });

        Schema::create('product_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('product_id')->constrained('products');
            // Weighted-average cost after this event, in paise per kg (4 decimals).
            $table->decimal('avg_paise_per_kg', 18, 4);
            $table->decimal('basis_kg', 15, 3);              // quantity the average now covers
            $table->string('source_type', 32);               // purchase_bill | production_batch | rate
            $table->uuid('source_id')->nullable();
            $table->json('data')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['product_id', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            // By-products are valued at this realisable rate when a batch closes; the rest of the cost goes to rice.
            $table->unsignedBigInteger('valuation_paise_per_kg')->nullable();
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
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('valuation_paise_per_kg');
        });
        foreach (['product_costs', 'bank_statement_lines', 'payment_allocations', 'payments', 'bank_files', 'purchase_bills',
            'period_locks', 'voucher_lines', 'vouchers', 'ledger_accounts', 'account_groups'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
