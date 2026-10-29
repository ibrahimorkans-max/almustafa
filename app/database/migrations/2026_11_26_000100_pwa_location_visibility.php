<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4:
 *  - where an entry was made, when the mill records it and the browser allowed it (lat, lng, accuracy, time);
 *  - data visibility windows set by the product owner: how far back a customer sees its records, per
 *    plan (the default) or per customer, for all modules or one; an access end with grace; overrides.
 *    Records are never deleted for it: they are only hidden from the customer's screens, reports and API;
 *  - 'report.export' (downloading reports and lists as Excel / PDF), given to every role that sees reports.
 */
return new class extends Migration
{
    private const LOCATED = ['gate_entries', 'paddy_stock_entries', 'expenses', 'roznamcha_pages'];

    public function up(): void
    {
        foreach (self::LOCATED as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->json('captured_location')->nullable());
        }

        Schema::create('data_visibility_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers');
            $table->foreignUuid('plan_id')->nullable()->constrained('plans');
            $table->string('module', 24)->default('all');
            $table->unsignedInteger('duration')->nullable();     // how far back: null = no limit
            $table->string('duration_unit', 8)->default('days'); // days | months
            $table->date('starts_on')->nullable();               // the rule applies from
            $table->date('expires_on')->nullable();              // access to the module ends (after the grace)
            $table->unsignedInteger('grace_days')->default(0);
            $table->string('status', 12)->default('active');     // active | inactive
            $table->date('override_until')->nullable();          // everything visible until this day (manual override)
            $table->string('override_reason', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['customer_id', 'module']);
            $table->index(['plan_id', 'module']);
        });

        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("select set_config('app.rls_bypass', 'on', true)");
            }
            if (! DB::table('permissions')->where('code', 'report.export')->exists() && DB::table('permissions')->where('code', 'report.view')->exists()) {
                DB::table('permissions')->insert(['code' => 'report.export', 'module' => 'reports', 'description' => 'Download reports and lists (Excel, PDF)', 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! DB::table('permissions')->where('code', 'report.export')->exists()) {
                return; // fresh install: roles are made from the templates
            }
            $roles = DB::table('role_permissions')->where('permission_code', 'report.view')->distinct()->get(['role_id', 'entity_id']);
            foreach ($roles as $r) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $r->role_id, 'permission_code' => 'report.export', 'entity_id' => $r->entity_id, 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission_code', 'report.export')->delete();
        DB::table('permissions')->where('code', 'report.export')->delete();
        Schema::dropIfExists('data_visibility_rules');
        foreach (self::LOCATED as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('captured_location'));
        }
    }
};
