<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exact place of a session from the browser's GPS / Wi-Fi position: area (neighbourhood, village,
 * road), the full address, the country's name, and when it was taken. The IP's own city / region /
 * country are kept apart (ip_*), so a browser far from its IP still stands out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->string('area', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('country_name', 80)->nullable();
            $table->string('ip_city', 100)->nullable();
            $table->string('ip_region', 100)->nullable();
            $table->string('ip_country', 2)->nullable();
            $table->timestamp('location_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->dropColumn(['area', 'address', 'country_name', 'ip_city', 'ip_region', 'ip_country', 'location_at']);
        });
    }
};
