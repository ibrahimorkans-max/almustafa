<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 10: expense categories (each mapped to a ledger; budgets come in phase 11), staff
 * reimbursement claims with their receipt lines, labour contractors (workers linked to a vendor
 * party) and the contractor bills built from those workers' attendance or piece work.
 */
return new class extends Migration
{
    private const TABLES = ['expense_categories', 'reimbursements', 'reimbursement_lines', 'contractor_bills', 'contractor_bill_lines'];

    private const APPEND_ONLY = ['reimbursement_lines', 'contractor_bill_lines'];

    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('kind', 10)->default('expense');      // expense | income
            $table->foreignUuid('ledger_account_id')->constrained('ledger_accounts');
            $table->boolean('claimable')->default(true);           // staff may claim it as a reimbursement
            $table->string('status', 12)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('reimbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->date('claim_date');
            $table->unsignedBigInteger('total_paise');
            $table->text('purpose');
            $table->string('status', 20)->default('submitted');  // submitted | checked | approved | paid | rejected
            $table->uuid('submitted_by')->nullable();
            $table->uuid('checked_by')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->foreignUuid('paid_from_ledger_id')->nullable()->constrained('ledger_accounts');
            $table->foreignUuid('payment_voucher_id')->nullable()->constrained('vouchers');
            $table->dateTime('paid_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('reimbursement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('reimbursement_id')->constrained('reimbursements');
            $table->foreignUuid('expense_category_id')->constrained('expense_categories');
            $table->date('spent_on');
            $table->string('description', 200);
            $table->unsignedBigInteger('amount_paise');
            $table->foreignUuid('receipt_file_id')->nullable()->constrained('stored_files');
            $table->dateTime('created_at', 3);
        });

        Schema::table('employees', function (Blueprint $table) {
            // A worker supplied by a labour contractor (a vendor party); null for the mill's own staff.
            $table->foreignUuid('contractor_party_id')->nullable()->constrained('parties');
        });

        Schema::create('contractor_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('party_id')->constrained('parties');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('basis', 12);                          // man_days | piece
            $table->unsignedBigInteger('gross_paise');
            $table->unsignedInteger('sales_tax_bp')->default(0);  // provincial sales tax on services
            $table->unsignedBigInteger('sales_tax_paise')->default(0);
            $table->unsignedInteger('wht_bp')->default(0);        // income tax withheld, s. 153(1)(b)
            $table->unsignedBigInteger('wht_paise')->default(0);
            $table->unsignedBigInteger('payable_paise');
            $table->string('status', 12)->default('draft');      // draft | approved | rejected
            $table->text('note')->nullable();
            $table->uuid('prepared_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['party_id', 'period_from']);
        });

        Schema::create('contractor_bill_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('contractor_bill_id')->constrained('contractor_bills');
            $table->foreignUuid('employee_id')->nullable()->constrained('employees');
            $table->string('description', 200);
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 20);
            $table->unsignedBigInteger('rate_paise');
            $table->unsignedBigInteger('amount_paise');
            $table->json('source')->nullable();                   // days or piece entries it came from
            $table->dateTime('created_at', 3);
        });

        TenantTables::secure(self::TABLES, self::APPEND_ONLY);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(self::APPEND_ONLY);
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contractor_party_id');
        });
    }
};
