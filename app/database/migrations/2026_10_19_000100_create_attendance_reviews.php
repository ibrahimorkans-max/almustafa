<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 3: the supervisor's decision on a flagged punch is its own append-only record (the punch
 * itself never changes), plus the uploading IP and the approval behind a manual entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('login_account_id');
            $table->uuid('approval_request_id')->nullable()->after('ip');   // manual entries
        });

        Schema::create('attendance_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('attendance_log_id')->constrained('attendance_logs');
            $table->string('decision', 8);                         // accept | reject
            $table->text('reason');
            $table->uuid('reviewed_by');
            $table->dateTime('reviewed_at', 3);
            $table->index(['attendance_log_id', 'reviewed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE attendance_reviews ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE attendance_reviews FORCE ROW LEVEL SECURITY');
            DB::unprepared('CREATE POLICY tenant_isolation ON attendance_reviews
                USING (app_rls_bypass() OR entity_id = app_entity_id())
                WITH CHECK (app_rls_bypass() OR entity_id = app_entity_id())');
            DB::unprepared('CREATE TRIGGER attendance_reviews_no_update_delete BEFORE UPDATE OR DELETE ON attendance_reviews
                FOR EACH ROW EXECUTE FUNCTION audit_log_reject_change()');
        } else {
            DB::unprepared("CREATE TRIGGER attendance_reviews_no_update BEFORE UPDATE ON attendance_reviews FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attendance_reviews is append-only (UPDATE rejected)'");
            DB::unprepared("CREATE TRIGGER attendance_reviews_no_delete BEFORE DELETE ON attendance_reviews FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'attendance_reviews is append-only (DELETE rejected)'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_reviews');
        Schema::table('attendance_logs', fn (Blueprint $table) => $table->dropColumn(['ip', 'approval_request_id']));
    }
};
