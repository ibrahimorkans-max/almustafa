<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary vouchers (App\Domain\Hr\SalaryVouchers): an employee's salary for a month (or a bonus / other payment) paid
 * on the spot — the salary filled from the employee's salary, an advance instalment and other deductions taken off,
 * paid in cash, by cheque or by bank, and printed. A month paid this way is not paid again by a payroll run, and the
 * other way round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('kind', 10);                            // salary | bonus | other
            $table->string('month', 7)->nullable();                // Y-m, for a salary
            $table->decimal('days', 5, 1)->nullable();             // a daily wage: days worked
            $table->bigInteger('gross_paise');
            $table->bigInteger('advance_paise')->default(0);       // advance / loan instalment taken off
            $table->foreignUuid('advance_id')->nullable()->constrained('advances');
            $table->bigInteger('other_deduction_paise')->default(0);
            $table->string('other_deduction_note', 120)->nullable();
            $table->bigInteger('net_paise');
            $table->string('mode', 12);                            // cash | cheque | ibft | raast | other
            $table->foreignUuid('ledger_id')->constrained('ledger_accounts');
            $table->string('reference', 60)->nullable();
            $table->timestamp('paid_at');
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at');
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'employee_id', 'month']);
        });
        TenantTables::secure(['salary_vouchers'], ['salary_vouchers']);
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_vouchers');
    }
};
