<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting documents attached to a record: lab result sheets, weighbridge slips, supplier invoices,
 * payment vouchers, approval papers. The file itself is content-addressed (stored_files, SHA-256), so it
 * can be proven unchanged; attachments are append-only — nothing attached is edited or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('attachable_type', 30);    // gate_entry | purchase_bill | payment | approval_request
            $table->uuid('attachable_id');
            $table->foreignUuid('stored_file_id')->constrained('stored_files');
            $table->string('kind', 30);               // lab_result | weighbridge_slip | supplier_invoice | payment_voucher | approval | other
            $table->string('note', 300)->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamp('created_at');
            $table->index(['attachable_type', 'attachable_id']);
        });

        TenantTables::secure(['attachments'], ['attachments']);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(['attachments']);
        Schema::dropIfExists('attachments');
    }
};
