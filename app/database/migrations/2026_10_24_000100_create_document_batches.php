<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 9: bulk generation. A batch issues one document type for many records on the queue,
 * keeps its progress and the records that failed (with the reason), and ends with one PDF to print
 * and a ZIP of the originals. Shared links may open a password-protected copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('doc_type', 30);
            $table->string('label', 120);
            $table->json('source_ids');
            $table->json('options');
            $table->string('status', 12)->default('queued');     // queued | running | done | failed
            $table->unsignedInteger('total');
            $table->unsignedInteger('done_count')->default(0);
            $table->json('document_ids')->nullable();
            $table->json('failed')->nullable();                   // source id => reason
            $table->string('pdf_path')->nullable();
            $table->string('zip_path')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['entity_id', 'created_at']);
        });

        TenantTables::secure(['document_batches']);

        // A shared link may open a copy protected with the employee's CNIC (last 5 digits).
        Schema::table('document_shares', function (Blueprint $table) {
            $table->boolean('protected')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('document_shares', function (Blueprint $table) {
            $table->dropColumn('protected');
        });
        Schema::dropIfExists('document_batches');
    }
};
