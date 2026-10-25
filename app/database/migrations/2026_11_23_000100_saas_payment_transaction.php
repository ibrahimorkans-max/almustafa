<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "I've paid" records the payment the way it is checked: the transaction ID exactly as the bank or wallet
 * printed it (reported_reference, stored cleaned: no spaces, capitals), which way it was paid
 * (reported_method_id) and the amount sent. A transaction ID can be reported for one invoice only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_invoices', function (Blueprint $table): void {
            $table->uuid('reported_method_id')->nullable()->after('reported_reference');
            $table->unsignedBigInteger('reported_amount_paise')->nullable()->after('reported_method_id');
            $table->index('reported_reference');
        });
    }

    public function down(): void
    {
        Schema::table('saas_invoices', function (Blueprint $table): void {
            $table->dropIndex(['reported_reference']);
            $table->dropColumn(['reported_method_id', 'reported_amount_paise']);
        });
    }
};
