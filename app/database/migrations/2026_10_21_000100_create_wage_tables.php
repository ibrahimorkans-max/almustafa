<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 5: effective-dated payroll settings, piece-work quantities, and salary advances / loans
 * with their recoveries. Settings, piece work and recoveries are append-only.
 */
return new class extends Migration
{
    private const TABLES = ['hr_settings', 'piece_work_entries', 'advances', 'advance_recoveries'];

    private const APPEND_ONLY = ['hr_settings', 'piece_work_entries', 'advance_recoveries'];

    public function up(): void
    {
        // A later row for the same key and an earlier or equal date wins; nothing is ever edited.
        Schema::create('hr_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('key', 60);
            $table->json('value');
            $table->date('effective_from');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['entity_id', 'key', 'effective_from']);
        });

        Schema::create('piece_work_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->date('work_date');
            $table->string('unit', 20);                          // bag | maund | ton | piece …
            $table->decimal('quantity', 15, 3);                  // negative = correction of an earlier entry
            $table->string('source_type', 30)->default('manual'); // manual | gate_entry | production_batch | correction
            $table->uuid('source_id')->nullable();
            $table->string('source_key', 120)->nullable();       // type:id:unit — the same source counts once per employee
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['employee_id', 'work_date']);
            $table->unique(['employee_id', 'source_key']);
        });

        Schema::create('advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('number', 40)->nullable();
            $table->string('kind', 10);                          // advance | loan
            $table->unsignedBigInteger('amount_paise');
            $table->unsignedBigInteger('installment_paise');
            $table->string('start_month', 7);                    // Y-m of the first recovery
            $table->text('reason');
            $table->string('status', 12)->default('requested'); // requested | approved | rejected | disbursed | closed
            $table->uuid('requested_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('paid_from_ledger_id')->nullable()->constrained('ledger_accounts');
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->dateTime('disbursed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('advance_recoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('advance_id')->constrained('advances');
            $table->unsignedBigInteger('amount_paise');
            $table->date('recovered_on');
            $table->string('source', 12);                        // payroll | cash
            $table->uuid('source_id')->nullable();               // payroll item
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index('advance_id');
        });

        TenantTables::secure(self::TABLES, self::APPEND_ONLY);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(self::APPEND_ONLY);
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
