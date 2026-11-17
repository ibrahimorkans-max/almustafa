<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A new user's first sign-in: where it came from (IP, place, device) is kept, the user confirms an e-mail address
 * by a link sent to it and fills in their own details before any screen opens. Logins that already exist have
 * signed in before: they are marked done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_accounts', function (Blueprint $table): void {
            $table->string('email', 191)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verify_token_hash', 64)->nullable();
            $table->timestamp('email_verify_sent_at')->nullable();
            $table->timestamp('first_sign_in_at')->nullable();
            $table->json('first_sign_in')->nullable();
            $table->timestamp('first_sign_in_completed_at')->nullable();
        });
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");
            }
            DB::table('login_accounts')->whereNull('first_sign_in_completed_at')->update(['first_sign_in_completed_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::table('login_accounts', function (Blueprint $table): void {
            $table->dropColumn(['email', 'email_verified_at', 'email_verify_token_hash', 'email_verify_sent_at', 'first_sign_in_at',
                'first_sign_in', 'first_sign_in_completed_at']);
        });
    }
};
