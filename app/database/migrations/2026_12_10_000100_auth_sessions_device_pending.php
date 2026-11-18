<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A web sign-in from a device not approved yet, by somebody with an e-mail on the login: held with no rights until the
 * 6-digit code sent to that e-mail is typed, then the device is approved (App\Domain\Auth\NewNetwork) — instead of
 * waiting for another Owner to approve it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->timestamp('device_pending_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->dropColumn('device_pending_at');
        });
    }
};
