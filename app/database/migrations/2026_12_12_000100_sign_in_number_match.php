<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every sign-in check by e-mail (first sign-in, new network or device, password reset) now has two codes in the
 * e-mail: the 6-digit code, typed first, and a 2-digit number picked from four on the screen
 * (App\Domain\Auth\EmailCode). The person's own second factor follows (App\Domain\Auth\SecondFactor, no table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_accounts', function (Blueprint $table): void {
            $table->string('email_match_hash', 64)->nullable();   // the 2-digit number of the e-mail (its keyed hash)
            $table->json('email_match_choices')->nullable();      // the four numbers on the screen, the right one among them
            $table->timestamp('email_code_ok_at')->nullable();    // the 6-digit code was right: the number comes next
        });
    }

    public function down(): void
    {
        Schema::table('login_accounts', function (Blueprint $table): void {
            $table->dropColumn(['email_match_hash', 'email_match_choices', 'email_code_ok_at']);
        });
    }
};
