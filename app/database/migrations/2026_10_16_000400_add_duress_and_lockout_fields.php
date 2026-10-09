<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_accounts', function (Blueprint $table) {
            // Duress PIN (hashed like a password); what happens when it is used; when it was last used.
            $table->string('duress_pin_hash')->nullable();
            $table->timestamp('duress_pin_set_at')->nullable();
            $table->boolean('duress_suspends')->default(true);
            $table->boolean('duress_wipes_device')->default(false);
            $table->timestamp('duress_flagged_at')->nullable();
            // Repeated temporary lockouts → stays locked until an administrator unlocks it.
            $table->unsignedSmallInteger('lockouts_24h')->default(0);
            $table->timestamp('last_lockout_at')->nullable();
            $table->boolean('needs_admin_unlock')->default(false);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('reported_lost_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('reported_lost_at'));
        Schema::table('login_accounts', fn (Blueprint $table) => $table->dropColumn([
            'duress_pin_hash', 'duress_pin_set_at', 'duress_suspends', 'duress_wipes_device', 'duress_flagged_at',
            'lockouts_24h', 'last_lockout_at', 'needs_admin_unlock',
        ]));
    }
};
