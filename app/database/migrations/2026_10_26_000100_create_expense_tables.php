<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 11: expenses and other income entered against categories (with a receipt), the
 * recurring templates that draft them each month, and monthly budgets per category.
 */
return new class extends Migration
{
    private const TABLES = ['recurring_expenses', 'expenses', 'expense_budgets'];

    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('name', 120);
            $table->foreignUuid('expense_category_id')->constrained('expense_categories');
            $table->unsignedBigInteger('amount_paise');
            $table->unsignedTinyInteger('day_of_month');           // 1–28: the draft's date each month
            $table->foreignUuid('pay_from_ledger_id')->nullable()->constrained('ledger_accounts'); // cash / bank
            $table->foreignUuid('party_id')->nullable()->constrained('parties');                   // or owed to a party
            $table->string('starts_month', 7);
            $table->string('ends_month', 7)->nullable();
            $table->boolean('active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->string('kind', 10);                              // expense | income (from the category)
            $table->foreignUuid('expense_category_id')->constrained('expense_categories');
            $table->date('entry_date');
            $table->unsignedBigInteger('amount_paise');
            $table->string('description', 300);
            $table->foreignUuid('cash_ledger_id')->nullable()->constrained('ledger_accounts'); // paid from / received into
            $table->foreignUuid('party_id')->nullable()->constrained('parties');               // or on credit with a party
            $table->foreignUuid('receipt_file_id')->nullable()->constrained('stored_files');
            $table->string('status', 12)->default('draft');          // draft | posted | cancelled
            $table->foreignUuid('recurring_expense_id')->nullable()->constrained('recurring_expenses');
            $table->string('recurring_month', 7)->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->uuid('posted_by')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->unique(['recurring_expense_id', 'recurring_month']); // one draft per template and month
            $table->index(['entity_id', 'status', 'entry_date']);
        });

        Schema::create('expense_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('expense_category_id')->constrained('expense_categories');
            $table->string('month', 7);
            $table->unsignedBigInteger('amount_paise');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['expense_category_id', 'month']);
        });

        TenantTables::secure(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
