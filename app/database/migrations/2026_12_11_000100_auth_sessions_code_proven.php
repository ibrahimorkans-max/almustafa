<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A held sign-in (new network or device) passes its checks one after the other: the e-mail code first (marked here),
 * then the QR code and number picked on a device already signed in (App\Domain\Auth\NewNetwork).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->timestamp('code_proven_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->dropColumn('code_proven_at');
        });
    }
};
