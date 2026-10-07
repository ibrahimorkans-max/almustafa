<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same isolation model as phase 1 (see 2026_10_07_000900) for the phase 2 tables,
 * plus append-only protection for the sync log and change feed.
 */
return new class extends Migration
{
    private const TABLES = [
        'brands', 'tax_rates', 'godowns', 'products', 'parties', 'party_bank_accounts', 'drivers', 'vehicles',
        'sync_events', 'sync_changes', 'sync_change_heads', 'sync_conflicts',
    ];

    private const APPEND_ONLY = ['sync_events', 'sync_changes'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Shared by every append-only table from now on: name the table in the error.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_log_reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION '% is append-only (% rejected)', TG_TABLE_NAME, TG_OP USING ERRCODE = 'insufficient_privilege';
                END
                $$;
            SQL);

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

            return;
        }

        foreach (self::APPEND_ONLY as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }
    }
};
