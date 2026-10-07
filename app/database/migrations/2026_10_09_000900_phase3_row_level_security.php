<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isolation for the phase 3 tables, append-only protection for every fact table,
 * and the per-agent request-signing secret on devices.
 */
return new class extends Migration
{
    private const TABLES = [
        'document_sequences', 'paddy_requirements', 'requirement_recipients', 'quotes', 'quote_revisions',
        'saudas', 'sauda_events', 'scales', 'scale_certificates', 'stored_files', 'deduction_rules',
        'gate_entries', 'gate_events', 'weighments', 'lab_tests',
    ];

    /** Facts: corrected by superseding rows / reversal events, never edited or deleted. */
    private const APPEND_ONLY = ['quote_revisions', 'sauda_events', 'gate_events', 'weighments', 'lab_tests', 'stored_files'];

    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // HMAC key for signed agent requests (encrypted cast); null for browsers/phones.
            $table->text('signing_secret')->nullable();
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

            return;
        }

        foreach (self::APPEND_ONLY as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only (UPDATE rejected)'");
            DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only (DELETE rejected)'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::APPEND_ONLY as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update_delete ON {$table}");
            }
            foreach (self::TABLES as $table) {
                DB::unprepared("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::unprepared("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }
        } else {
            foreach (self::APPEND_ONLY as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }

        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('signing_secret'));
    }
};
