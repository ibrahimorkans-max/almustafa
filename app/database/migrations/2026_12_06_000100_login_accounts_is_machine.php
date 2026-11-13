<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A login used by a machine (a weighbridge PC's gate agent, made by pairing), not by a person: it is not a
 * staff user of the plan, and it is the only kind of login a pairing code may take over. Logins already made
 * by pairing (`wb.…` with an agent device) are marked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_accounts', function (Blueprint $table) {
            $table->boolean('is_machine')->default(false);
        });
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");
            }
            $paired = DB::table('agent_pairings')->distinct()->pluck('login_account_id');
            DB::table('login_accounts')->whereIn('id', $paired)->where('username', 'like', 'wb.%')->update(['is_machine' => true]);
        });
    }

    public function down(): void
    {
        Schema::table('login_accounts', function (Blueprint $table) {
            $table->dropColumn('is_machine');
        });
    }
};
