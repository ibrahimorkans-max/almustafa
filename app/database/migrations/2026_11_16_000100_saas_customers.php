<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SaaS: customers above the mills.
 *
 *  - customers: each company using the system (its plan, trial and billing come in later steps);
 *  - entities.customer_id: every mill belongs to one customer;
 *  - login_accounts.is_platform_admin: the provider's own accounts (the platform console), the only ones
 *    that may still see across customers;
 *  - row-level security: an Owner working across "all firms" now sees the mills of its own customer only
 *    (app.customer_id), never another customer's; the full bypass stays for the system and the platform.
 *
 * Existing data becomes the first customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 160);
            $table->string('status', 16)->default('active');   // trial | active | past_due | suspended | cancelled
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::table('entities', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable()->after('id');
            $table->index('customer_id');
        });
        Schema::table('login_accounts', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
        });

        $this->bypass(function (): void {
            if (DB::table('entities')->count() === 0) {
                return;   // fresh install: the first customer is made at sign-up
            }
            $id = (string) Str::uuid7();
            DB::table('customers')->insert([
                'id' => $id, 'code' => 'C0001', 'name' => (string) (config('ricemill.brand.name') ?: 'First customer'),
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('entities')->whereNull('customer_id')->update(['customer_id' => $id]);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_customer_id() RETURNS uuid LANGUAGE sql STABLE AS $$
                SELECT nullif(current_setting('app.customer_id', true), '')::uuid
            $$;
            -- A row of a mill that belongs to the current customer (its Owner, in one mill or across "all firms").
            CREATE OR REPLACE FUNCTION app_in_customer(row_entity uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT app_customer_id() IS NOT NULL
                   AND EXISTS (SELECT 1 FROM entities e WHERE e.id = row_entity AND e.customer_id = app_customer_id())
            $$;
        SQL);

        // Every table isolated by mill: also the customer's own mills in "all firms" mode.
        $tables = collect(DB::select("SELECT tablename FROM pg_policies WHERE schemaname = current_schema() AND policyname = 'tenant_isolation'"))
            ->pluck('tablename')->reject(fn (string $t) => in_array($t, ['entities', 'persons'], true));
        foreach ($tables as $table) {
            DB::unprepared("ALTER POLICY tenant_isolation ON {$table}
                USING (app_rls_bypass() OR entity_id = app_entity_id() OR app_in_customer(entity_id))
                WITH CHECK (app_rls_bypass() OR entity_id = app_entity_id() OR app_in_customer(entity_id))");
        }
        // A customer's Owner sees all its mills (to choose one), never another customer's.
        DB::unprepared('ALTER POLICY tenant_isolation ON entities
            USING (app_rls_bypass() OR id = app_entity_id() OR (app_customer_id() IS NOT NULL AND customer_id = app_customer_id()))
            WITH CHECK (app_rls_bypass() OR id = app_entity_id() OR (app_customer_id() IS NOT NULL AND customer_id = app_customer_id()))');
        DB::unprepared('ALTER POLICY tenant_isolation ON persons
            USING (
                app_rls_bypass()
                OR created_in_entity_id = app_entity_id()
                OR app_in_customer(created_in_entity_id)
                OR EXISTS (SELECT 1 FROM login_accounts la WHERE la.person_id = persons.id
                           AND (la.entity_id = app_entity_id() OR app_in_customer(la.entity_id)))
            )
            WITH CHECK (app_rls_bypass() OR created_in_entity_id = app_entity_id() OR app_in_customer(created_in_entity_id))');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $tables = collect(DB::select("SELECT tablename FROM pg_policies WHERE schemaname = current_schema() AND policyname = 'tenant_isolation'"))
                ->pluck('tablename')->reject(fn (string $t) => in_array($t, ['entities', 'persons'], true));
            foreach ($tables as $table) {
                DB::unprepared("ALTER POLICY tenant_isolation ON {$table}
                    USING (app_rls_bypass() OR entity_id = app_entity_id()) WITH CHECK (app_rls_bypass() OR entity_id = app_entity_id())");
            }
            DB::unprepared('ALTER POLICY tenant_isolation ON entities USING (app_rls_bypass() OR id = app_entity_id())
                WITH CHECK (app_rls_bypass() OR id = app_entity_id())');
            DB::unprepared('ALTER POLICY tenant_isolation ON persons
                USING (app_rls_bypass() OR created_in_entity_id = app_entity_id()
                    OR EXISTS (SELECT 1 FROM login_accounts la WHERE la.person_id = persons.id AND la.entity_id = app_entity_id()))
                WITH CHECK (app_rls_bypass() OR created_in_entity_id = app_entity_id())');
            DB::unprepared('DROP FUNCTION IF EXISTS app_in_customer(uuid); DROP FUNCTION IF EXISTS app_customer_id();');
        }
        Schema::table('login_accounts', fn (Blueprint $t) => $t->dropColumn('is_platform_admin'));
        Schema::table('entities', function (Blueprint $t) {
            $t->dropIndex(['customer_id']);
            $t->dropColumn('customer_id');
        });
        Schema::dropIfExists('customers');
    }

    private function bypass(callable $callback): void
    {
        DB::transaction(function () use ($callback): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            $callback();
        });
    }
};
