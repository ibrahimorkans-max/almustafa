<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('action', 64)->index();
            $table->json('payload');
            $table->string('summary');
            $table->text('reason')->nullable();
            // pending | executed | rejected | failed | cancelled
            $table->string('status', 16)->default('pending')->index();
            $table->uuid('maker_login_account_id');
            $table->uuid('maker_person_id');
            $table->uuid('checker_login_account_id')->nullable();
            $table->uuid('checker_person_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
