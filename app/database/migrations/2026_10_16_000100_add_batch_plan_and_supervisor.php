<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->decimal('planned_kg', 15, 3)->nullable()->after('line');   // paddy planned for the batch
            $table->foreignUuid('supervisor_login_account_id')->nullable()->after('planned_kg')->constrained('login_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_login_account_id');
            $table->dropColumn('planned_kg');
        });
    }
};
