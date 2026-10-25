<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS phase 4, billing: the provider's invoices to its customers (monthly, paid by bank transfer) and a
 * counter for their numbers. Not mill records: no entity, no row-level policy; the application shows a
 * customer only its own invoices, and the platform admin all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_counters', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });

        Schema::create('saas_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers');
            $table->string('number', 30)->unique();
            $table->string('kind', 12)->default('monthly');   // monthly | other
            $table->string('description', 200);
            $table->uuid('plan_id')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedBigInteger('amount_paise');
            $table->string('status', 12)->default('open');   // open | paid | void
            $table->timestamp('issued_at');
            $table->timestamp('due_at');
            // The customer says it has paid (bank transfer): its reference and receipt, for the platform to check.
            $table->timestamp('reported_at')->nullable();
            $table->string('reported_reference', 80)->nullable();
            $table->date('reported_paid_on')->nullable();
            $table->text('reported_note')->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->uuid('reported_by')->nullable();
            // The platform's record of the money received.
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_amount_paise')->nullable();
            $table->string('payment_reference', 80)->nullable();
            $table->text('payment_note')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->string('last_reminder', 12)->nullable();   // due_soon | due | overdue
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_invoices');
        Schema::dropIfExists('saas_counters');
    }
};
