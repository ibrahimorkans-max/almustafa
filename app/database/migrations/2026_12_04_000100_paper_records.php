<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Old records brought in from the mill's paper registers (App\Domain\Accounts\PaperRecords): opening balances of
 * parties, cash, bank and other ledgers, opening stock, and past purchases, sales, payments, receipts, expenses and
 * journal entries — each posted to the books (and stock) on its paper date — plus register entries kept as they were
 * written (old weighbridge slips, lab sheets, anything else). Each keeps where it was written (register, page) and
 * may carry a photo of the page. A record is never edited: a wrong one is cancelled (its postings reversed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('kind', 20);
            $table->date('record_date');
            $table->string('book_ref', 160)->nullable();          // register and page: "Roznamcha 2024, page 37"
            $table->string('paper_number', 60)->nullable();       // the number written on the paper (bill, slip, receipt no.)
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            $table->text('description')->nullable();
            $table->bigInteger('amount_paise')->nullable();
            $table->decimal('quantity_kg', 14, 3)->nullable();
            $table->json('details')->nullable();                  // what else was on the paper (ledgers, rate, vehicle, weights …)
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->foreignUuid('stock_entry_id')->nullable()->constrained('stock_ledger');
            $table->string('import_batch', 40)->nullable();       // rows brought in together from one spreadsheet
            $table->uuid('entered_by')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->index(['entity_id', 'kind', 'record_date']);
            $table->index(['entity_id', 'party_id']);
        });
        TenantTables::secure(['paper_records'], []);
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_records');
    }
};
