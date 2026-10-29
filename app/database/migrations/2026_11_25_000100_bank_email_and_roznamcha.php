<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting automation (Phase 3):
 *  - bank_email_templates: how a bank's notification e-mail is read (set by the product owner; global);
 *  - email_connections: a mill's mailbox connected to read bank notifications (OAuth tokens or an app
 *    password, encrypted), with its bank account and sync interval;
 *  - bank_email_transactions: what was read from each e-mail, with the party it matched (or why not),
 *    import and reconciliation status. An e-mail is read once (unique per mailbox); the same transaction
 *    seen twice is marked a duplicate;
 *  - email_sync_runs: one row per sync, for the health page and import history;
 *  - roznamcha_pages / roznamcha_lines: the daily cash book, scanned or typed, read by OCR, checked
 *    by a person and then posted once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bank_name', 80);
            $table->string('sender_pattern', 200);         // regex on the From address
            $table->string('subject_pattern', 200)->nullable();
            $table->string('amount_pattern', 300);           // regex with a named group "amount"
            $table->string('credit_pattern', 300)->nullable(); // matches → money in
            $table->string('debit_pattern', 300)->nullable();  // matches → money out
            $table->string('reference_pattern', 300)->nullable();
            $table->string('account_pattern', 300)->nullable();      // our account (last digits)
            $table->string('counterparty_pattern', 300)->nullable(); // name of the other side
            $table->string('counterparty_account_pattern', 300)->nullable(); // their IBAN / account
            $table->string('date_pattern', 300)->nullable();
            $table->string('date_format', 40)->nullable();  // PHP format of the date group, e.g. d-M-Y H:i
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('email_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('provider', 16);                 // google | microsoft | imap
            $table->string('email', 160);
            $table->foreignUuid('ledger_account_id')->nullable()->constrained('ledger_accounts'); // the mill's bank account
            $table->text('credentials')->nullable();        // encrypted JSON: tokens or IMAP settings
            $table->string('status', 16)->default('pending'); // pending | connected | error | disconnected
            $table->unsignedInteger('sync_minutes')->default(30);
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('oauth_state', 64)->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'provider', 'email']);
        });

        Schema::create('email_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('email_connection_id')->constrained('email_connections');
            $table->string('trigger', 12);                 // schedule | manual
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['email_connection_id', 'started_at']);
        });

        Schema::create('bank_email_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('email_connection_id')->constrained('email_connections');
            $table->foreignUuid('bank_email_template_id')->nullable()->constrained('bank_email_templates');
            $table->foreignUuid('ledger_account_id')->nullable()->constrained('ledger_accounts');
            $table->string('message_id', 255);              // the mailbox's id of the e-mail
            $table->string('message_from', 160)->nullable();
            $table->string('message_subject', 255)->nullable();
            $table->dateTime('received_at')->nullable();
            $table->text('excerpt')->nullable();              // the text that was read (no attachments)
            $table->string('bank_name', 80)->nullable();
            $table->date('txn_date')->nullable();
            $table->time('txn_time')->nullable();
            $table->string('direction', 6)->nullable();       // credit (in) | debit (out)
            $table->unsignedBigInteger('amount_paise')->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('account_hint', 40)->nullable();   // our account as printed (masked)
            $table->string('counterparty', 160)->nullable();
            $table->string('counterparty_account', 40)->nullable();
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            $table->string('match_status', 12)->default('unmatched'); // matched | suggested | unmatched
            $table->string('match_reason', 200)->nullable();
            $table->string('import_status', 12)->default('parsed');   // parsed | failed | duplicate
            $table->string('parse_error', 300)->nullable();
            $table->string('reconciliation_status', 16)->default('pending'); // pending | confirmed | ignored | on_statement
            $table->char('fingerprint', 64)->nullable();
            $table->foreignUuid('bank_statement_line_id')->nullable()->constrained('bank_statement_lines');
            $table->uuid('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['email_connection_id', 'message_id']);
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'fingerprint']);
            $table->index(['entity_id', 'reconciliation_status']);
        });

        Schema::create('roznamcha_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->date('entry_date');
            $table->string('source', 12);                   // camera | scanner | pdf | image | manual
            $table->foreignUuid('stored_file_id')->nullable()->constrained('stored_files');
            $table->text('ocr_text')->nullable();
            $table->string('ocr_engine', 20)->nullable();   // tesseract | claude | none
            $table->string('status', 12)->default('draft'); // draft | verified | posted
            $table->foreignUuid('cash_ledger_id')->nullable()->constrained('ledger_accounts');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'entry_date']);
        });

        Schema::create('roznamcha_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('roznamcha_page_id')->constrained('roznamcha_pages')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            $table->foreignUuid('ledger_account_id')->nullable()->constrained('ledger_accounts');
            $table->string('account_text', 160)->nullable(); // as read or typed, before an account is chosen
            $table->unsignedBigInteger('debit_paise')->default(0);  // cash paid out to this account
            $table->unsignedBigInteger('credit_paise')->default(0); // cash received from this account
            $table->string('narration', 300)->nullable();
            $table->string('reference', 80)->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            // The product owner can switch bank e-mail import off for a customer.
            $table->boolean('bank_email_enabled')->default(true);
        });

        TenantTables::secure(['email_connections', 'email_sync_runs', 'bank_email_transactions', 'roznamcha_pages', 'roznamcha_lines']);
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('bank_email_enabled'));
        Schema::dropIfExists('roznamcha_lines');
        Schema::dropIfExists('roznamcha_pages');
        Schema::dropIfExists('bank_email_transactions');
        Schema::dropIfExists('email_sync_runs');
        Schema::dropIfExists('email_connections');
        Schema::dropIfExists('bank_email_templates');
    }
};
