<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four ways a mill may weigh a lot of paddy, switched on and off by the SaaS owner only (App\Domain\Gate\WeighModes):
 *
 *  - weighment_mode_configs   a company's defaults (customer_id) or one mill's own setting (entity_id), four switches;
 *  - weighment_mode_changes   every change to them, before and after, who and why — never changed or removed;
 *  - gate_entries.weigh_mode / mode_config_version
 *                             the mode a vehicle was registered under and the setting it came from: a vehicle keeps
 *                             its workflow whatever is switched later;
 *  - gate_slip_versions       each slip given for a vehicle (a weight-only slip first, then the final one) — kept, never
 *                             overwritten.
 *
 * Paddy lots already registered keep the workflow they had: weighed first (mode 1) or lab first (mode 4), from the
 * mill's "weigh before lab" setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighment_mode_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers');
            $table->foreignUuid('entity_id')->nullable()->constrained('entities');
            $table->boolean('lab_before_slip')->default(false);
            $table->boolean('no_lab')->default(false);
            $table->boolean('weigh_first_lab_later')->default(false);
            $table->boolean('lab_first')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique('customer_id');
            $table->unique('entity_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE weighment_mode_configs ADD CONSTRAINT weighment_mode_configs_one_level CHECK ((customer_id IS NULL) <> (entity_id IS NULL))');
        }

        Schema::create('weighment_mode_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('config_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->string('level', 10);                 // company | mill
            $table->string('action', 20);                // set | inherit (a mill back to its company's defaults)
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->unsignedInteger('version');
            $table->uuid('changed_by')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'created_at']);
        });
        TenantTables::secure([], ['weighment_mode_changes']);

        Schema::table('gate_entries', function (Blueprint $table) {
            $table->string('weigh_mode', 30)->nullable();
            $table->string('mode_config_version', 40)->nullable();
        });

        Schema::create('gate_slip_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('gate_entry_id')->constrained('gate_entries');
            $table->unsignedInteger('version');
            $table->string('kind', 20);                  // initial (weight only) | final
            $table->string('weigh_mode', 30)->nullable();
            $table->json('snapshot');
            $table->string('verification_code', 20);
            $table->uuid('gate_event_id')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestamp('issued_at');
            $table->unique(['gate_entry_id', 'version']);
        });
        TenantTables::secure(['gate_slip_versions'], ['gate_slip_versions']);

        // Lots already registered keep their workflow (the mill's setting as it is now); slips already given are kept
        // as version 1 of their vehicle's slips.
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");
            }
            $labFirst = DB::table('settings')->where('key', 'gate.weigh_before_lab')->whereNotNull('entity_id')->get(['entity_id', 'value'])
                ->filter(fn ($s) => json_decode((string) $s->value, true) === 'off')->pluck('entity_id')->all();
            $globalOff = json_decode((string) DB::table('settings')->where('key', 'gate.weigh_before_lab')->whereNull('entity_id')->value('value'), true) === 'off';
            $paddy = fn () => DB::table('gate_entries')->where('direction', 'inbound')->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', '!=', 'other'))->whereNull('weigh_mode');
            $paddy()->whereIn('entity_id', $labFirst)->update(['weigh_mode' => 'lab_first', 'mode_config_version' => 'before-modes']);
            $paddy()->update(['weigh_mode' => $globalOff ? 'lab_first' : 'lab_before_slip', 'mode_config_version' => 'before-modes']);

            $slips = DB::table('gate_events')->where('event', 'slip_issued')->get(['id', 'entity_id', 'gate_entry_id', 'data', 'actor_login_account_id', 'occurred_at']);
            foreach ($slips as $s) {
                $mode = DB::table('gate_entries')->where('id', $s->gate_entry_id)->value('weigh_mode');
                $data = json_decode((string) $s->data, true) ?: [];
                DB::table('gate_slip_versions')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(), 'entity_id' => $s->entity_id, 'gate_entry_id' => $s->gate_entry_id,
                    'version' => 1, 'kind' => 'final', 'weigh_mode' => $mode, 'snapshot' => json_encode($data),
                    'verification_code' => \App\Domain\Gate\SlipRenderer::verificationCode($s->gate_entry_id, $data),
                    'gate_event_id' => $s->id, 'issued_by' => $s->actor_login_account_id, 'issued_at' => $s->occurred_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        TenantTables::dropTriggers(['weighment_mode_changes', 'gate_slip_versions']);
        Schema::dropIfExists('gate_slip_versions');
        Schema::table('gate_entries', fn (Blueprint $table) => $table->dropColumn(['weigh_mode', 'mode_config_version']));
        Schema::dropIfExists('weighment_mode_changes');
        Schema::dropIfExists('weighment_mode_configs');
    }
};
