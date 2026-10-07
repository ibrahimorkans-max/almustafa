<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL row-level security: the database-side half of entity isolation.
 *
 * The application sets two settings on its connection (see App\Domain\Tenancy\TenantContext):
 *   app.entity_id  — the entity the current request acts in
 *   app.rls_bypass — 'on' only for system work and Super Admin
 *
 * FORCE makes the policies apply to the table owner too, so the application user
 * (which owns the tables) is still filtered. MariaDB/MySQL have no RLS; there the
 * Eloquent global scope is the only layer.
 */
return new class extends Migration
{
    /** Tables whose entity_id is NOT NULL. */
    private const STRICT = [
        'login_accounts', 'mfa_recovery_codes', 'ip_allowlist', 'devices',
        'auth_sessions', 'api_tokens', 'approval_requests', 'audit_log',
    ];

    /** Tables where entity_id NULL marks a global row that every entity may read. */
    private const WITH_GLOBAL_ROWS = ['roles', 'role_permissions', 'settings'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_rls_bypass() RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT coalesce(current_setting('app.rls_bypass', true), '') = 'on'
            $$;
            CREATE OR REPLACE FUNCTION app_entity_id() RETURNS uuid LANGUAGE sql STABLE AS $$
                SELECT nullif(current_setting('app.entity_id', true), '')::uuid
            $$;
        SQL);

        foreach ([...self::STRICT, ...self::WITH_GLOBAL_ROWS] as $table) {
            $this->enable($table);
            DB::unprepared("CREATE POLICY tenant_isolation ON {$table}
                USING (app_rls_bypass() OR entity_id = app_entity_id())
                WITH CHECK (app_rls_bypass() OR entity_id = app_entity_id())");
        }

        foreach (self::WITH_GLOBAL_ROWS as $table) {
            DB::unprepared("CREATE POLICY global_rows_read ON {$table} FOR SELECT USING (entity_id IS NULL)");
        }

        $this->enable('entities');
        DB::unprepared('CREATE POLICY tenant_isolation ON entities
            USING (app_rls_bypass() OR id = app_entity_id())
            WITH CHECK (app_rls_bypass() OR id = app_entity_id())');

        $this->enable('persons');
        DB::unprepared('CREATE POLICY tenant_isolation ON persons
            USING (
                app_rls_bypass()
                OR created_in_entity_id = app_entity_id()
                OR EXISTS (SELECT 1 FROM login_accounts la
                           WHERE la.person_id = persons.id AND la.entity_id = app_entity_id())
            )
            WITH CHECK (app_rls_bypass() OR created_in_entity_id = app_entity_id())');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([...self::STRICT, ...self::WITH_GLOBAL_ROWS, 'entities', 'persons'] as $table) {
            DB::unprepared("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::unprepared("DROP POLICY IF EXISTS global_rows_read ON {$table}");
            DB::unprepared("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS app_entity_id(); DROP FUNCTION IF EXISTS app_rls_bypass();');
    }

    private function enable(string $table): void
    {
        DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }
};
