<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The mill's city and PIN code are mandatory on e-invoices and e-way bills. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('city', 80)->nullable();
            $table->string('pincode', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn(['city', 'pincode']);
        });
    }
};
