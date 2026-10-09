<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withholding income tax on purchases (Income Tax Ordinance 2001, s. 153(1)(a)) and the per-party
 * exemption (e.g. growers selling their own produce, or an exemption certificate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->unsignedBigInteger('wht_paise')->default(0);        // withheld from what the supplier is paid
            $table->unsignedInteger('wht_rate_bp')->default(0);
            $table->unsignedBigInteger('wht_base_paise')->default(0);   // part of this bill above the yearly threshold
            $table->boolean('wht_filer')->default(false);               // supplier on the Active Taxpayer List when posted
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->boolean('wht_exempt')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropColumn(['wht_paise', 'wht_rate_bp', 'wht_base_paise', 'wht_filer']);
        });
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('wht_exempt');
        });
    }
};
