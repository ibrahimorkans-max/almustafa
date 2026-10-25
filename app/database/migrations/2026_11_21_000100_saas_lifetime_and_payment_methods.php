<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS: lifetime access (a customer the product owner never bills: no trial end, no invoices, never
 * read-only or paused automatically), and the ways customers can pay (bank, mobile wallet, Raast …),
 * each with its QR code, shown wherever a customer is asked to pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('lifetime')->default(false)->after('trial_ends_at');
            $table->timestamp('lifetime_since')->nullable()->after('lifetime');
        });

        Schema::create('saas_payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);                 // "Meezan Bank", "JazzCash", "Raast"
            $table->string('kind', 16)->default('bank'); // bank | wallet | raast | other
            $table->string('account_title', 120)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->string('iban', 40)->nullable();
            $table->text('instructions')->nullable();
            $table->string('qr_path', 255)->nullable();  // the QR picture the bank or wallet gave (private file)
            $table->string('qr_text', 500)->nullable();  // or the text to make a QR from (Raast ID, payment link …)
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_payment_methods');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['lifetime', 'lifetime_since']));
    }
};
