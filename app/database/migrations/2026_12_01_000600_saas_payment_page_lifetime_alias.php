<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform (SaaS owner):
 *  - customers.alias          a name of the owner's own for the account, shown only on the platform screens;
 *  - customers.payment_page   whether its Owner sees "My subscription" when the platform shows it to selected
 *                             customers only (setting saas.payment_page);
 *  - customers.lifetime_paid_paise  what it paid once for lifetime access;
 *  - plans.price_paise_lifetime     a plan's one-time price for lifetime access (empty: not offered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('alias', 120)->nullable();
            $table->boolean('payment_page')->default(true);
            $table->unsignedBigInteger('lifetime_paid_paise')->nullable();
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('price_paise_lifetime')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('price_paise_lifetime'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['alias', 'payment_page', 'lifetime_paid_paise']));
    }
};
