<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The language each person chose (en, ur, ps, sd); empty = the system's default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_accounts', function (Blueprint $table): void {
            $table->string('locale', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('login_accounts', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
