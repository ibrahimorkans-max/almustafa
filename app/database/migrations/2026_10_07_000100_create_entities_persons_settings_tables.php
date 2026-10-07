<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            // Encrypted casts (ciphertext); never queried directly.
            $table->text('gstin')->nullable();
            $table->text('pan')->nullable();
            $table->char('state_code', 2)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('persons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            // The entity that registered this person; it may edit the record.
            $table->foreignUuid('created_in_entity_id')->constrained('entities');
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL = global setting.
            $table->foreignUuid('entity_id')->nullable()->constrained('entities');
            $table->string('key', 100);
            $table->json('value');
            $table->uuid('updated_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['entity_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('persons');
        Schema::dropIfExists('entities');
    }
};
