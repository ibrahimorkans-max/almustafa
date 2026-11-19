<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick bills:
 *  - cancelled from their screen (voucher, money received and stock reversed; the sauda gets its quantity back);
 *  - a bill on a sauda counts against the sauda's quantity (`sauda_kg`, the weight it delivered);
 * Dispatch orders:
 *  - "Direct" dispatch (by hand, bike, small quantity; weights typed): the order is dispatched at once with its bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_bills', function (Blueprint $table): void {
            $table->decimal('sauda_kg', 15, 3)->nullable();     // delivered on the sauda by this bill
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->string('cancel_reason', 500)->nullable();
        });
        Schema::table('dispatch_orders', function (Blueprint $table): void {
            $table->foreignUuid('quick_bill_id')->nullable()->constrained('quick_bills');
            $table->string('vehicle_type', 20)->nullable();
            $table->timestamp('dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('quick_bill_id');
            $table->dropColumn(['vehicle_type', 'dispatched_at']);
        });
        Schema::table('quick_bills', function (Blueprint $table): void {
            $table->dropColumn(['sauda_kg', 'cancelled_at', 'cancelled_by', 'cancel_reason']);
        });
    }
};
