<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's chats, kept day by day for each person (Assistant → History): a past chat can be opened and
 * continued. Only the person who had it sees it; chats older than App\Models\Assistant\AssistantChat::KEEP_DAYS
 * are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_chats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->uuid('login_account_id');
            $table->date('chat_date');
            $table->string('title', 120)->nullable();
            $table->json('messages');
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();
            $table->index(['login_account_id', 'chat_date']);
        });
        TenantTables::secure(['assistant_chats']);
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_chats');
    }
};
