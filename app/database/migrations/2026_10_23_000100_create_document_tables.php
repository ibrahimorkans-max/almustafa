<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR phase 8: the documents engine. Templates and their versions (append-only), every generated
 * document with its frozen content (append-only: a correction is a new revision that points at the
 * one it replaces), share links (phase 9) and the access log (append-only).
 */
return new class extends Migration
{
    private const TABLES = ['document_templates', 'document_template_versions', 'generated_documents', 'document_shares', 'document_access_log'];

    private const APPEND_ONLY = ['document_template_versions', 'generated_documents', 'document_access_log'];

    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('doc_type', 30);
            $table->string('name', 80);
            $table->string('layout', 12);                        // classic | compact | detailed | minimal
            $table->boolean('is_default')->default(false);
            $table->string('status', 12)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'doc_type', 'name']);
        });

        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('template_id')->constrained('document_templates');
            $table->unsignedInteger('version_no');
            $table->json('settings');                            // header / footer / terms text, colours, signatures, default toggles
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->unique(['template_id', 'version_no']);
        });

        Schema::create('generated_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('doc_type', 30);
            $table->string('number', 60);
            $table->unsignedSmallInteger('revision')->default(0);
            $table->string('source_type', 40);
            $table->uuid('source_id');
            $table->foreignUuid('employee_id')->nullable()->constrained('employees');
            $table->foreignUuid('template_version_id')->constrained('document_template_versions');
            $table->string('layout', 12);
            $table->string('language', 12);                      // en | ur | bilingual
            $table->string('paper', 12);                         // a4 | a5 | half_a4 | thermal80 | thermal58
            $table->json('options');                             // toggles and digits as first issued
            $table->json('payload');                             // the frozen content: figures, never labels
            $table->foreignUuid('file_id')->constrained('stored_files');
            $table->char('sha256', 64);
            $table->string('verify_code', 14);
            $table->uuid('supersedes_id')->nullable();
            $table->text('reason')->nullable();                  // why a revision was issued
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->unique(['entity_id', 'doc_type', 'number', 'revision'], 'generated_documents_number_unique');
            $table->index(['source_type', 'source_id']);
            $table->index(['employee_id', 'doc_type']);
        });
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->foreign('supersedes_id')->references('id')->on('generated_documents');
        });

        Schema::create('document_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('generated_document_id')->constrained('generated_documents');
            $table->string('channel', 12);                       // link | email | whatsapp | sms
            $table->text('recipient')->nullable();               // encrypted
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedSmallInteger('max_opens')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('document_access_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('generated_document_id')->constrained('generated_documents');
            $table->string('action', 16);                        // issue | view | download | reprint | share | revoke | open_link | refused_link
            $table->uuid('account_id')->nullable();
            $table->uuid('share_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['generated_document_id', 'action']);
        });

        TenantTables::secure(self::TABLES, self::APPEND_ONLY);
    }

    public function down(): void
    {
        TenantTables::dropTriggers(self::APPEND_ONLY);
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
