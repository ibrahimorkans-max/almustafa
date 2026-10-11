<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 12: indexes for the reports and dashboards of phase 11. The profit and loss and cash flow
 * read voucher lines by date; stock on a date and milling cost read the stock ledger by time and
 * movement; the dashboard counts payslips issued since a payroll was finalized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->index(['entity_id', 'voucher_date'], 'voucher_lines_entity_date_idx');
        });
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->index(['entity_id', 'occurred_at'], 'stock_ledger_entity_time_idx');
            $table->index(['entity_id', 'movement', 'occurred_at'], 'stock_ledger_entity_move_time_idx');
        });
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->index(['entity_id', 'doc_type', 'created_at'], 'generated_documents_type_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', fn (Blueprint $table) => $table->dropIndex('generated_documents_type_time_idx'));
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropIndex('stock_ledger_entity_move_time_idx');
            $table->dropIndex('stock_ledger_entity_time_idx');
        });
        Schema::table('voucher_lines', fn (Blueprint $table) => $table->dropIndex('voucher_lines_entity_date_idx'));
    }
};
