<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time pairing codes for weighbridge PCs (App\Domain\Gate\AgentPairing): the office makes a code in the
 * ERP, the PC types it once (`ricemill-agent pair`) and receives its login, device_uid, signing secret and
 * weighbridge, so nothing is copied or typed by hand. Only the code's hash is kept; the payload is encrypted
 * and erased when the code is used; a code lasts 30 minutes and works once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_pairings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('device_id')->constrained('devices');
            $table->foreignUuid('login_account_id')->constrained('login_accounts');
            $table->uuid('scale_id')->nullable();
            $table->string('code_hash', 64)->unique();
            $table->text('payload')->nullable();                  // encrypted; erased once used
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at');
        });
        TenantTables::secure(['agent_pairings'], []);
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_pairings');
    }
};
