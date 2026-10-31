<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The roznamcha's daily budget (Accounts → Roznamcha → Daily budget): how much cash may be paid out on a day.
 * The usual amount is a mill setting (roznamcha.daily_budget); a day can have its own amount here. A page that
 * takes its day over the budget is posted only with the reason, kept on the page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roznamcha_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->date('budget_date');
            $table->unsignedBigInteger('amount_paise');
            $table->string('note', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'budget_date']);
        });
        TenantTables::secure(['roznamcha_budgets']);

        Schema::table('roznamcha_pages', function (Blueprint $table) {
            $table->string('over_budget_reason', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('roznamcha_pages', fn (Blueprint $table) => $table->dropColumn('over_budget_reason'));
        Schema::dropIfExists('roznamcha_budgets');
    }
};
