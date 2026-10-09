<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 4: shifts, holidays, leave types, leave requests and the append-only leave ledger
 * (balances are sums of ledger entries, never an editable number).
 */
return new class extends Migration
{
    private const TABLES = ['shifts', 'holidays', 'leave_types', 'leave_requests', 'leave_ledger'];

    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('start_time', 5);                     // HH:MM PKT
            $table->string('end_time', 5);                       // earlier than start = crosses midnight
            $table->unsignedSmallInteger('break_minutes')->default(30);
            $table->unsignedSmallInteger('grace_late_minutes')->default(10);
            $table->unsignedSmallInteger('grace_early_minutes')->default(10);
            $table->unsignedSmallInteger('full_day_minutes')->default(450);
            $table->unsignedSmallInteger('half_day_minutes')->default(225);
            $table->unsignedSmallInteger('overtime_after_minutes')->default(30); // beyond the shift's length
            $table->json('weekly_offs');                         // ISO days, e.g. [7]
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignUuid('shift_id')->nullable()->after('designation_id')->constrained('shifts');
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->date('date');
            $table->string('name', 120);
            $table->string('kind', 12)->default('festival');     // national | festival | local
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'date']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 10);
            $table->string('name', 120);
            $table->boolean('paid')->default(true);
            $table->decimal('annual_days', 5, 1)->default(0);
            $table->boolean('carry_forward')->default(false);
            $table->decimal('max_carry_days', 5, 1)->default(0);
            $table->boolean('half_day_allowed')->default(true);
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('leave_type_id')->constrained('leave_types');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('half_day', 6)->default('none');      // none | first | second (single day only)
            $table->decimal('days', 5, 1);
            $table->text('reason');
            $table->string('status', 10)->default('pending');    // pending | approved | rejected | cancelled
            $table->uuid('requested_by');
            $table->uuid('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['employee_id', 'from_date']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('leave_type_id')->constrained('leave_types');
            $table->unsignedSmallInteger('year');
            $table->string('kind', 14);                          // opening | accrual | taken | reversal | carry_forward | lapse | adjustment
            $table->decimal('days', 6, 1);                       // signed
            $table->uuid('leave_request_id')->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['employee_id', 'leave_type_id', 'year']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::unprepared("CREATE POLICY tenant_isolation ON {$table}
                    USING (app_rls_bypass() OR entity_id = app_entity_id())
                    WITH CHECK (app_rls_bypass() OR entity_id = app_entity_id())");
            }
            DB::unprepared('CREATE TRIGGER leave_ledger_no_update_delete BEFORE UPDATE OR DELETE ON leave_ledger
                FOR EACH ROW EXECUTE FUNCTION audit_log_reject_change()');
        } else {
            DB::unprepared("CREATE TRIGGER leave_ledger_no_update BEFORE UPDATE ON leave_ledger FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'leave_ledger is append-only (UPDATE rejected)'");
            DB::unprepared("CREATE TRIGGER leave_ledger_no_delete BEFORE DELETE ON leave_ledger FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'leave_ledger is append-only (DELETE rejected)'");
        }
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('shift_id'));
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
