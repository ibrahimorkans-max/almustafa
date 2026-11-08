<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lab, set up by each mill within what the SaaS owner allows:
 *
 *  - lab_parameters: besides the standard and grace limits, a minimum and maximum acceptable value, a tolerance
 *    (a result this much above standard still counts as standard), whether the test is mandatory, whether a lot
 *    in its grace band needs a supervisor's approval to be accepted, and whether it may change the payable weight
 *    (only then are its deduction rules applied);
 *  - lab_time_rules: how long the lab has for a lot (from its arrival or its loaded weight, to its result or its
 *    decision), whether to alert on expiry, and what happens to a late result (allowed and flagged, allowed only
 *    with a supervisor's approval, or refused);
 *  - lab_feature_configs: the lab features the SaaS owner allows a company (its defaults) or one mill — own tests,
 *    quality deductions, time grace, negotiation. With no setting every feature is allowed (as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_parameters', function (Blueprint $table) {
            $table->unsignedInteger('min_bp')->nullable();
            $table->unsignedInteger('max_bp')->nullable();
            $table->unsignedInteger('tolerance_bp')->default(0);
            $table->boolean('mandatory')->default(false);
            $table->boolean('needs_approval')->default(false);
            $table->boolean('affects_weight')->default(true);
        });

        Schema::create('lab_time_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->unsignedInteger('minutes');
            $table->string('starts_at_event', 20)->default('arrived');      // arrived | gross_weighed
            $table->string('ends_at_event', 20)->default('lab_result');     // lab_result | lab_decision
            $table->boolean('alert_on_expiry')->default(true);
            $table->string('late_rule', 12)->default('approval');           // allow | approval | block
            $table->boolean('active')->default(true);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique('entity_id');
        });
        TenantTables::secure(['lab_time_rules']);

        Schema::create('lab_feature_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->nullable()->unique()->constrained('customers');
            $table->foreignUuid('entity_id')->nullable()->unique()->constrained('entities');
            $table->boolean('custom_tests')->default(true);
            $table->boolean('deductions')->default(true);
            $table->boolean('time_grace')->default(true);
            $table->boolean('negotiation')->default(true);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_feature_configs');
        Schema::dropIfExists('lab_time_rules');
        Schema::table('lab_parameters', fn (Blueprint $table) => $table->dropColumn(['min_bp', 'max_bp', 'tolerance_bp', 'mandatory', 'needs_approval', 'affects_weight']));
    }
};
