<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the system spends on outside services (App\Domain\Usage):
 *
 *  - service_usages          one row for every call to an outside service (AI, WhatsApp, e-mail, push, mailbox, maps,
 *                            OCR, SMS…): company, mill, who or which automatic process, module, why, when (UTC),
 *                            quantity, success, the price that applied and its cost. Never changed or removed; a
 *                            retry with the same idempotency key is not counted twice;
 *  - service_usage_updates   what is learned later about a usage (delivered / read / failed, the provider's actual
 *                            cost, reconciled) — added beside it, never written over it;
 *  - service_prices          the SaaS owner's price list: per provider and service, from a date, for every company or
 *                            one company; free allowance, tiers, tax, monthly fee, quota. A new price is a new row;
 *  - provider_expenses       what the providers actually billed (for reconciliation with the estimates);
 *  - usage_statements        each company's monthly usage charge, as worked out (kept as issued).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 20)->unique();                 // U-2026-000123: the usage ID people quote
            $table->string('idempotency_key', 80)->unique();
            $table->uuid('customer_id')->nullable()->index();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities');
            $table->string('channel', 12);                             // api | sms | whatsapp | email | push
            $table->string('category', 20);                            // ai | messaging | email | push | mailbox | maps | ocr | sms | other
            $table->string('provider', 60);
            $table->string('service', 80);
            $table->string('endpoint', 160)->nullable();
            $table->string('module', 60);
            $table->string('action', 120);
            $table->string('description', 300)->nullable();
            $table->uuid('login_account_id')->nullable();
            $table->string('process', 120)->nullable();                // the automatic process, when nobody pressed a button
            $table->string('recipient_masked', 60)->nullable();
            $table->string('template', 80)->nullable();
            $table->string('related_type', 40)->nullable();
            $table->string('related_id', 40)->nullable();
            $table->string('provider_ref', 120)->nullable()->index(); // the provider's message / request id
            $table->unsignedInteger('quantity')->default(1);            // requests, SMS segments, messages, e-mails
            $table->unsignedInteger('units')->nullable();               // e.g. AI tokens
            $table->boolean('success');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error', 200)->nullable();
            $table->uuid('price_id')->nullable();
            $table->decimal('unit_price', 16, 6)->nullable();
            $table->decimal('estimated_cost', 16, 6)->default(0);
            $table->decimal('charge', 16, 6)->default(0);              // what the company is charged for it
            $table->string('currency', 3)->default('PKR');
            $table->decimal('actual_cost', 16, 6)->nullable();         // as the provider reported it (e.g. AI)
            $table->string('actual_currency', 3)->nullable();
            $table->string('billing_status', 12)->default('unbilled'); // unbilled | free | included | billed
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'occurred_at']);
            $table->index(['entity_id', 'occurred_at']);
            $table->index(['channel', 'occurred_at']);
        });

        Schema::create('service_usage_updates', function (Blueprint $table) {
            $table->id();
            $table->uuid('service_usage_id')->index();
            $table->uuid('entity_id')->nullable();
            $table->string('kind', 20);                                // delivery | actual_cost | reconciled
            $table->string('status', 20)->nullable();                  // sent | delivered | read | failed
            $table->decimal('amount', 16, 6)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('note', 200)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('service_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->nullable()->index();          // null: every company
            $table->string('channel', 12);
            $table->string('provider', 60);                            // '*' : any provider of the channel
            $table->string('service', 80)->default('*');
            $table->string('model', 16)->default('pay_per_use');       // pay_per_use | fixed_package | actual | custom
            $table->decimal('unit_price', 16, 6)->default(0);          // what it costs per unit (estimate)
            $table->decimal('charge_price', 16, 6)->nullable();        // what a company is charged per unit (null: as unit_price)
            $table->string('currency', 3)->default('PKR');
            $table->date('effective_from');
            $table->unsignedInteger('free_allowance')->default(0);     // units a month at no charge
            $table->json('tiers')->nullable();                         // [{"from": 1001, "price": "0.80"}]
            $table->decimal('tax_percent', 6, 3)->default(0);
            $table->decimal('monthly_fee', 16, 2)->default(0);         // a fixed package's monthly fee
            $table->unsignedInteger('monthly_quota')->nullable();       // alert above this
            $table->string('note', 200)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['channel', 'provider', 'effective_from']);
        });

        Schema::create('provider_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 60);
            $table->string('channel', 12)->nullable();
            $table->date('period');                                    // first day of the month billed
            $table->decimal('amount', 16, 6);
            $table->string('currency', 3)->default('PKR');
            $table->string('reference', 120)->nullable();
            $table->string('note', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['provider', 'period']);
        });

        Schema::create('usage_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->date('period');
            $table->json('lines');
            $table->decimal('subtotal', 16, 2);
            $table->decimal('tax', 16, 2);
            $table->decimal('total', 16, 2);
            $table->string('currency', 3)->default('PKR');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['customer_id', 'period']);
        });

        // A mill's people see their mill's usage only; the SaaS owner reads all (as the system).
        TenantTables::secure(['service_usages', 'service_usage_updates'], ['service_usages', 'service_usage_updates', 'service_prices', 'provider_expenses', 'usage_statements']);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(['service_usages', 'service_usage_updates', 'service_prices', 'provider_expenses', 'usage_statements']);
        foreach (['usage_statements', 'provider_expenses', 'service_prices', 'service_usage_updates', 'service_usages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
