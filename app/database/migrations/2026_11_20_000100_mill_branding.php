<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each mill's own look on paper (SaaS: every customer prints as itself): Urdu name, tagline, email and
 * website, its logo and small emblem, its two colours, its invoice terms, a note on weighment slips, a
 * footer line on every document, and the bank account printed on its sales invoices. Empty: the
 * defaults (the first installation keeps its configured brand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->string('name_ur', 160)->nullable();
            $table->string('tagline', 160)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('website', 160)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('emblem_path', 255)->nullable();
            $table->string('color_primary', 7)->nullable();
            $table->string('color_accent', 7)->nullable();
            $table->text('invoice_terms')->nullable();
            $table->string('slip_note', 300)->nullable();
            $table->string('footer_note', 200)->nullable();
            $table->string('bank_title', 120)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account', 40)->nullable();
            $table->string('bank_iban', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entities', fn (Blueprint $t) => $t->dropColumn([
            'name_ur', 'tagline', 'email', 'website', 'logo_path', 'emblem_path', 'color_primary', 'color_accent',
            'invoice_terms', 'slip_note', 'footer_note', 'bank_title', 'bank_name', 'bank_account', 'bank_iban',
        ]));
    }
};
