<?php

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily wages for casual labour that is not registered as staff: work categories with a rate (per day,
 * bag, maund…), labour groups that work under one leader (mukadam), and one wage sheet per day of work
 * of a group (or of a single worker). A sheet is posted to the books as a direct expense, paid in cash,
 * from a bank account, or owed until it is paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_wage_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('unit', 10)->default('day');          // day | hour | bag | maund | ton | trip | piece
            $table->bigInteger('rate_paise');                    // per person per day, or per unit of work
            $table->string('status', 12)->default('active');
            $table->timestamps();
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('daily_wage_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('name', 120);                         // e.g. "Akram's loading group"
            $table->string('leader_name', 120);                  // the mukadam / jamadar who brings the group and takes the pay
            $table->string('leader_phone', 20)->nullable();
            $table->string('leader_cnic', 15)->nullable();       // optional: no registration needed
            $table->foreignUuid('default_category_id')->nullable()->constrained('daily_wage_categories');
            $table->unsignedSmallInteger('usual_size')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('active');
            $table->timestamps();
        });

        Schema::create('daily_wage_sheets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('number', 40);
            $table->date('work_date');
            $table->foreignUuid('group_id')->nullable()->constrained('daily_wage_groups');
            $table->string('worker_name', 120)->nullable();      // a single worker without a group
            $table->foreignUuid('category_id')->constrained('daily_wage_categories');
            $table->string('unit', 10);
            $table->unsignedSmallInteger('workers');
            $table->decimal('quantity', 12, 3);                  // days per person (unit day / hour) or units of work
            $table->bigInteger('rate_paise');
            $table->bigInteger('gross_paise');
            $table->bigInteger('extra_paise')->default(0);       // overtime, bonus, tea money
            $table->bigInteger('deduction_paise')->default(0);   // advance already given, food
            $table->bigInteger('net_paise');
            $table->text('worker_names')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('status', 12)->default('draft');      // draft | posted (owed) | paid | cancelled
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->foreignUuid('payment_voucher_id')->nullable()->constrained('vouchers');
            $table->foreignUuid('paid_from_ledger_id')->nullable()->constrained('ledger_accounts');
            $table->uuid('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'work_date']);
            $table->index(['entity_id', 'status']);
        });

        TenantTables::secure(['daily_wage_categories', 'daily_wage_groups', 'daily_wage_sheets']);
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_wage_sheets');
        Schema::dropIfExists('daily_wage_groups');
        Schema::dropIfExists('daily_wage_categories');
    }
};
