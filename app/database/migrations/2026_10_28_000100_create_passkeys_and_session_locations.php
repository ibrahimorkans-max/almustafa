<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security phase: passkeys (WebAuthn credentials, one row per device key), where and on what each
 * session signed in (city and country from the IP, the browser's own position when allowed, the
 * browser and system), and the per-account "trusted devices only" switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('authenticatable_id')->constrained('login_accounts');
            $table->string('name', 100);                    // what the person called it: "Office PC", "My phone"
            // Base64 of the authenticator's id on PostgreSQL; 700 keeps MariaDB's unique index under its 3,072-byte limit.
            $table->string('credential_id', 700)->unique();
            $table->json('data');                            // public key and counters (webauthn-lib)
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('authenticatable_id');
        });

        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->string('browser', 60)->nullable();
            $table->string('os', 60)->nullable();
            $table->string('country', 2)->nullable();        // ISO code, from the IP
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->unsignedInteger('location_accuracy_m')->nullable();
            $table->string('location_source', 10)->nullable(); // ip | browser | local
            $table->string('sign_in_method', 12)->nullable();  // password | passkey
        });

        Schema::table('login_accounts', function (Blueprint $table) {
            $table->boolean('require_trusted_device')->default(false);
        });

        TenantTables::secure(['passkeys']);
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->dropColumn(['browser', 'os', 'country', 'region', 'city', 'latitude', 'longitude', 'location_accuracy_m', 'location_source', 'sign_in_method']);
        });
        Schema::table('login_accounts', function (Blueprint $table) {
            $table->dropColumn('require_trusted_device');
        });
    }
};
