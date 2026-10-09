<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 1: employee master, documents, exit clearance, salary components and effective-dated
 * salary structures. Personal identifiers are encrypted (with blind indexes for uniqueness);
 * salary structures are append-only — a change is a new version, never an edit.
 */
return new class extends Migration
{
    private const TABLES = [
        'departments', 'designations', 'employees', 'employee_documents', 'employee_clearance_items',
        'salary_components', 'salary_structures', 'salary_structure_lines',
    ];

    private const APPEND_ONLY = ['salary_structures', 'salary_structure_lines'];

    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'name']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 40);
            $table->foreignUuid('person_id')->constrained('persons');
            $table->foreignUuid('photo_file_id')->nullable()->constrained('stored_files');
            $table->foreignUuid('department_id')->nullable()->constrained('departments');
            $table->foreignUuid('designation_id')->nullable()->constrained('designations');
            $table->string('employment_type', 16);              // permanent | contract | daily_wage | piece_rate
            $table->string('status', 16)->default('active');    // active | on_notice | exited
            $table->date('joining_date');
            $table->text('date_of_birth')->nullable();           // encrypted
            $table->text('address')->nullable();                 // encrypted
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            // Identifiers: encrypted value + keyed hash (uniqueness / search) + last 4 for masked display.
            $table->text('cnic')->nullable();
            $table->char('cnic_index', 64)->nullable();
            $table->char('cnic_last4', 4)->nullable();
            $table->text('ntn')->nullable();
            $table->char('ntn_index', 64)->nullable();
            $table->char('ntn_last4', 4)->nullable();
            // Salary account: set and changed only through maker-checker (EmployeeBankAccountChange).
            $table->text('bank_account_number')->nullable();
            $table->char('bank_account_index', 64)->nullable();
            $table->char('bank_account_last4', 4)->nullable();
            $table->string('bank_code', 4)->nullable();               // from the IBAN, e.g. HABB
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_holder', 120)->nullable();
            $table->uuid('bank_approval_request_id')->nullable();
            // Exit
            $table->unsignedSmallInteger('notice_period_days')->default(30);
            $table->date('notice_given_on')->nullable();
            $table->date('last_working_day')->nullable();
            $table->text('exit_reason')->nullable();
            $table->date('exited_on')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
            $table->unique(['entity_id', 'cnic_index']);
            $table->unique(['entity_id', 'ntn_index']);
            $table->index(['entity_id', 'status']);
            $table->index(['person_id']);
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('kind', 20);                          // cnic | ntn | id_proof | contract | bank_proof | photo | other
            $table->string('title', 160);
            $table->foreignUuid('file_id')->constrained('stored_files');
            $table->date('valid_until')->nullable();
            $table->string('status', 16)->default('active');     // active | superseded
            $table->uuid('superseded_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['entity_id', 'valid_until']);
        });

        Schema::create('employee_clearance_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('item', 160);
            $table->dateTime('done_at')->nullable();
            $table->uuid('done_by')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['employee_id']);
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('kind', 12);                          // earning | deduction
            $table->boolean('is_statutory')->default(false);
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort')->default(100);
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('salary_structures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->date('effective_from');
            $table->string('pay_basis', 12);                     // monthly | daily | piece_rate
            $table->text('reason');
            $table->uuid('supersedes_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('salary_structure_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('salary_structure_id')->constrained('salary_structures');
            $table->foreignUuid('salary_component_id')->constrained('salary_components');
            $table->unsignedBigInteger('amount_paise');          // per month, per day or per unit (see pay_basis / unit)
            $table->string('unit', 20)->nullable();              // piece-rate unit, e.g. bag, quintal
            $table->dateTime('created_at', 3);
            $table->unique(['salary_structure_id', 'salary_component_id'], 'salary_lines_structure_component_unique'); // MariaDB: names ≤ 64 chars
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
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
