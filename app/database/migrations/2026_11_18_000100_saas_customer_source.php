<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS phase 3: how a customer came: 'signup' (public sign-up) or 'platform' (opened by the platform
 * admin). Empty for the first installation's customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('source', 16)->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
