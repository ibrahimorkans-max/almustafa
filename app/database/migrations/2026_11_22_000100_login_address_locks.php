<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wrong sign-ins counted per account AND per address (IP), so a stranger guessing a password locks out
 * only their own address, never the real person signing in from the mill. The whole account is locked
 * only by many failures from many addresses (see LoginService). The address is kept hashed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_address_locks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('login_account_id');
            $table->char('ip_hash', 64);
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('locked_until')->nullable();   // a pause (growing delay) or the 15-minute lock
            $table->timestamp('updated_at')->nullable();
            $table->unique(['login_account_id', 'ip_hash']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_address_locks');
    }
};
