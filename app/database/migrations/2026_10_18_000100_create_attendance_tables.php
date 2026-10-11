<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 2: geofenced sites, employee device binding, attendance consent and punches.
 * Punches and consents are append-only facts; the review of a flagged punch (phase 3) is a
 * separate record, so the original evidence never changes.
 */
return new class extends Migration
{
    private const TABLES = ['sites', 'employee_devices', 'attendance_consents', 'attendance_logs'];

    private const APPEND_ONLY = ['attendance_consents', 'attendance_logs'];

    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('kind', 20);                          // mill | godown | procurement_point | other
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m');
            $table->json('time_windows')->nullable();            // [{days:[1..7], from:"06:00", to:"22:00"}], PKT; null = any time
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('employee_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('device_id')->constrained('devices');
            $table->string('status', 16)->default('pending');    // pending | approved | rejected | revoked
            $table->dateTime('requested_at');
            $table->uuid('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['employee_id', 'device_id']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('attendance_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->uuid('login_account_id');
            $table->uuid('device_id')->nullable();
            $table->string('notice_version', 20);
            $table->dateTime('accepted_at', 3);
            $table->string('ip', 45)->nullable();
            $table->index(['employee_id', 'notice_version']);
        });

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('kind', 4);                            // in | out
            $table->string('source', 10);                         // pwa | app | import | manual
            $table->dateTime('occurred_at', 3);                   // device time as sent (UTC)
            $table->dateTime('received_at', 3);
            $table->dateTime('estimated_at', 3)->nullable();      // reconstructed from the monotonic clock
            $table->string('time_check', 12);                     // verified | unverified | changed
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->foreignUuid('site_id')->nullable()->constrained('sites');
            $table->unsignedInteger('distance_m')->nullable();
            $table->char('selfie_sha256', 64)->nullable();
            $table->uuid('device_id')->nullable();
            $table->uuid('login_account_id')->nullable();
            $table->uuid('client_event_id');
            $table->string('status', 10);                         // valid | flagged | rejected
            $table->json('reasons');
            $table->json('signals')->nullable();
            $table->dateTime('created_at', 3);
            $table->unique(['entity_id', 'client_event_id']);
            $table->index(['employee_id', 'occurred_at']);
            $table->index(['entity_id', 'status', 'occurred_at']);
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
