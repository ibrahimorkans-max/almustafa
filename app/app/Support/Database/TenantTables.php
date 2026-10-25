<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration helper: forced row-level security on tenant tables (PostgreSQL) and database-level
 * append-only triggers (PostgreSQL and MariaDB), the same way every earlier phase did it by hand.
 */
final class TenantTables
{
    /**
     * @param  list<string>  $tables  tables with an entity_id column
     * @param  list<string>  $appendOnly  tables whose rows may never be updated or deleted
     */
    public static function secure(array $tables, array $appendOnly = []): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Tables made before the SaaS step are moved to the customer-aware policy by that migration.
            // (The column, not the function: a rebuilt database keeps its functions but not its tables.)
            $customers = Schema::hasColumn('entities', 'customer_id');
            $rule = $customers ? 'app_rls_bypass() OR entity_id = app_entity_id() OR app_in_customer(entity_id)' : 'app_rls_bypass() OR entity_id = app_entity_id()';
            foreach ($tables as $table) {
                DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                // Its mill; or, for an Owner across "all firms", the mills of its own customer (SaaS).
                DB::unprepared("CREATE POLICY tenant_isolation ON {$table} USING ({$rule}) WITH CHECK ({$rule})");
            }
            foreach ($appendOnly as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_update_delete BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION audit_log_reject_change()");
            }

            return;
        }
        foreach ($appendOnly as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only (UPDATE rejected)'");
            DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only (DELETE rejected)'");
        }
    }

    /** @param  list<string>  $appendOnly */
    public static function dropTriggers(array $appendOnly): void
    {
        if (DB::getDriverName() === 'pgsql') {
            return; // dropped with the table
        }
        foreach ($appendOnly as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }
    }
}
