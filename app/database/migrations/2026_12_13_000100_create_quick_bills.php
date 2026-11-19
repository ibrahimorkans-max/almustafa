<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick bills (App\Domain\Sales\QuickBills): one bill on the spot for whatever happens at the counter or the gate —
 *  - sale: rice or anything else, of any size (a bike, by hand), one or many products, each with its own weights;
 *  - purchase: paddy (after the gate entry and the lab), deductions typed by hand;
 *  - exchange: rice given for paddy, both on one bill (hisaab);
 *  - milling: somebody's own paddy milled into rice, for the labour (mazdoori) per maund;
 *  - weighing: an outside vehicle weighed only (both weights typed).
 * Every line has its weights (gross, empty, deduction per bag and other deduction → net) and its rate per maund, which
 * may be changed; charges (vehicle rent, labour …) are added to the bill or taken off it; the bill is paid now (cash,
 * cheque, bank …), in part, or later. Posting makes the voucher and moves the stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->string('kind', 12);                           // sale | purchase | exchange | milling | weighing
            $table->timestamp('billed_at');                       // date and time on the bill
            $table->foreignUuid('party_id')->nullable()->constrained('parties');
            $table->string('customer_name', 120)->nullable();     // a walk-in customer (no party)
            $table->string('customer_phone', 20)->nullable();
            $table->foreignUuid('sauda_id')->nullable()->constrained('saudas');
            $table->foreignUuid('gate_entry_id')->nullable()->constrained('gate_entries');
            $table->string('vehicle_type', 20)->nullable();       // truck … motorcycle, by hand
            $table->string('vehicle_no', 20)->nullable();
            $table->string('driver_name', 80)->nullable();
            $table->decimal('first_weight_kg', 15, 3)->nullable(); // weighing only: the two weights, typed
            $table->decimal('second_weight_kg', 15, 3)->nullable();
            $table->bigInteger('out_paise')->default(0);          // goods the mill gives
            $table->bigInteger('in_paise')->default(0);           // goods the mill takes
            $table->bigInteger('service_paise')->default(0);      // milling labour, weighing fee
            $table->bigInteger('charges_add_paise')->default(0);
            $table->bigInteger('charges_less_paise')->default(0);
            $table->bigInteger('net_paise');                      // > 0: the party pays the mill; < 0: the mill pays the party
            $table->bigInteger('paid_paise')->default(0);         // settled so far (either way)
            $table->string('status', 10)->default('posted');      // posted | cancelled
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'billed_at']);
            $table->index(['entity_id', 'party_id']);
        });

        Schema::create('quick_bill_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('quick_bill_id')->constrained('quick_bills')->cascadeOnDelete();
            $table->smallInteger('line_no');
            $table->string('direction', 8);                       // out (mill gives) | in (mill takes) | service
            $table->foreignUuid('product_id')->nullable()->constrained('products');
            $table->string('description', 150);
            $table->string('variety', 80)->nullable();
            $table->foreignUuid('godown_id')->nullable()->constrained('godowns');
            $table->integer('bags')->nullable();
            $table->decimal('gross_kg', 15, 3);
            $table->decimal('tare_kg', 15, 3)->default(0);
            $table->decimal('deduction_per_bag_kg', 8, 3)->default(0);
            $table->decimal('other_deduction_kg', 15, 3)->default(0);
            $table->decimal('net_kg', 15, 3);
            $table->bigInteger('rate_paise_per_maund');
            $table->bigInteger('amount_paise');
        });

        Schema::create('quick_bill_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('quick_bill_id')->constrained('quick_bills')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('effect', 8);                          // add (the party pays it) | less (taken off the bill)
            $table->bigInteger('amount_paise');
        });

        Schema::create('quick_bill_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('quick_bill_id')->constrained('quick_bills');
            $table->string('direction', 4);                       // in (received) | out (paid, through a payment request)
            $table->string('mode', 12);                           // cash | cheque | ibft | rtgs | raast | pay_order | card | other
            $table->foreignUuid('ledger_id')->constrained('ledger_accounts');
            $table->bigInteger('amount_paise');
            $table->string('reference', 60)->nullable();          // cheque no., transaction ID
            $table->timestamp('paid_at');
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->uuid('payment_id')->nullable();               // App\Models\Accounts\Payment, for money paid out
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at');
            $table->index(['quick_bill_id']);
        });

        TenantTables::secure(['quick_bills', 'quick_bill_lines', 'quick_bill_charges', 'quick_bill_payments'], []);
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_bill_payments');
        Schema::dropIfExists('quick_bill_charges');
        Schema::dropIfExists('quick_bill_lines');
        Schema::dropIfExists('quick_bills');
    }
};
