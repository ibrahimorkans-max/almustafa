<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR phases 6 and 7: allowance rules, approved pay adjustments (bonuses, fines, other deductions),
 * the monthly payroll run with its locked attendance snapshot, the calculated items (append-only:
 * a recalculation adds a new set), payslip numbers, salary payments and the employees'
 * acknowledgements of cash, and bank transfer files.
 */
return new class extends Migration
{
    private const TABLES = [
        'allowance_rules', 'pay_adjustments', 'payroll_runs', 'payroll_run_attendance', 'payroll_items',
        'payslips', 'payroll_bank_files', 'payroll_payments', 'payroll_payment_acks',
    ];

    private const APPEND_ONLY = ['payroll_run_attendance', 'payroll_items', 'payslips', 'payroll_payments', 'payroll_payment_acks'];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('eobi_number', 30)->nullable();
            $table->string('pessi_number', 30)->nullable();
            $table->boolean('eobi_applicable')->default(true);
            $table->boolean('pessi_applicable')->default(true);
        });

        Schema::create('allowance_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);                          // pay head shown on the slip
            $table->string('name', 120);
            $table->string('method', 20);                        // fixed | pct_basic | per_day | attendance
            $table->unsignedBigInteger('amount_paise')->default(0);
            $table->unsignedInteger('rate_bp')->default(0);
            $table->boolean('prorate')->default(true);           // fixed: by paid days
            $table->unsignedSmallInteger('max_absent_days')->nullable(); // attendance: allowed absences
            $table->unsignedSmallInteger('max_late_days')->nullable();
            $table->json('departments')->nullable();             // null = all
            $table->json('employment_types')->nullable();        // null = all
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 12)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['entity_id', 'status']);
        });

        Schema::create('pay_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('month', 7);
            $table->string('type', 12);                          // bonus | earning | fine | deduction
            $table->string('code', 20);                          // FESTIVAL, PERF, ARREARS, FINE, CANTEEN …
            $table->string('name', 120);
            $table->unsignedBigInteger('amount_paise');
            $table->text('reason');
            $table->string('status', 12)->default('requested');  // requested | approved | rejected
            $table->uuid('batch_id')->nullable();                // generated together (festival bonus)
            $table->uuid('requested_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['employee_id', 'month']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40)->nullable();
            $table->string('month', 7);
            // draft → locked (attendance) → calculated → approved → finalized
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('calculation_no')->default(0);
            $table->unsignedInteger('lock_no')->default(0);         // each (re)lock snapshots attendance again
            $table->uuid('locked_by')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->uuid('calculated_by')->nullable();
            $table->dateTime('calculated_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->uuid('finalized_by')->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->json('totals')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'month']);
        });

        Schema::create('payroll_run_attendance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs');
            $table->unsignedInteger('lock_no');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->json('days');
            $table->json('totals');
            $table->dateTime('created_at', 3);
            $table->unique(['payroll_run_id', 'lock_no', 'employee_id'], 'payroll_attendance_run_lock_employee_unique');
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs');
            $table->unsignedInteger('calculation_no');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->json('employee');                            // code, name, department … as on the slip
            $table->json('result');                              // lines, info, warnings (WageEngine)
            $table->bigInteger('gross_paise');
            $table->bigInteger('deductions_paise');
            $table->bigInteger('net_paise');
            $table->bigInteger('employer_paise');
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->dateTime('created_at', 3);
            $table->unique(['payroll_run_id', 'calculation_no', 'employee_id'], 'payroll_items_run_calc_employee_unique');
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs');
            $table->foreignUuid('payroll_item_id')->constrained('payroll_items');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('number', 40);
            $table->string('month', 7);
            $table->bigInteger('net_paise');
            $table->dateTime('created_at', 3);
            $table->unique(['entity_id', 'number']);
            $table->unique('payroll_item_id');
        });

        Schema::create('payroll_bank_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs');
            $table->string('number', 40);
            $table->foreignUuid('ledger_account_id')->constrained('ledger_accounts');
            $table->string('format', 20);
            $table->unsignedInteger('payment_count');
            $table->unsignedBigInteger('total_paise');
            $table->char('sha256', 64);
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
        });

        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs');
            $table->foreignUuid('payroll_item_id')->constrained('payroll_items');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('number', 40);
            $table->unsignedBigInteger('amount_paise');
            $table->string('mode', 10);                          // bank | cash | cheque
            $table->foreignUuid('ledger_account_id')->constrained('ledger_accounts');
            $table->foreignUuid('bank_file_id')->nullable()->constrained('payroll_bank_files');
            $table->text('iban')->nullable();                    // encrypted, frozen at payment
            $table->string('reference', 60)->nullable();
            $table->date('paid_on');
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index('payroll_item_id');
        });

        // The employee confirms cash received: a photo of the signature or thumb impression.
        Schema::create('payroll_payment_acks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('payroll_payment_id')->constrained('payroll_payments');
            $table->string('method', 12);                        // signature | thumb
            $table->foreignUuid('file_id')->constrained('stored_files');
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->unique('payroll_payment_id');
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
            $table->dropColumn(['eobi_number', 'pessi_number', 'eobi_applicable', 'pessi_applicable']);
        });
    }
};
