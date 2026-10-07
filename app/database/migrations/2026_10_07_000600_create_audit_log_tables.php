<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only, hash-chained audit log. No foreign keys: audit rows outlive everything.
 * UPDATE / DELETE (and TRUNCATE on PostgreSQL) are rejected by triggers for every DB user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('chain', 64);
            $table->unsignedBigInteger('seq');
            $table->uuid('entity_id')->nullable()->index();
            $table->dateTime('occurred_at', 6)->index();
            $table->uuid('actor_login_account_id')->nullable()->index();
            $table->uuid('actor_person_id')->nullable();
            $table->boolean('acting_as_super_admin')->default(false);
            $table->uuid('device_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 64)->nullable();
            // `json` (not jsonb) keeps the exact text that was hashed.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('meta')->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->unique(['chain', 'seq']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('chain', 64)->primary();
            $table->unsignedBigInteger('seq');
            $table->char('hash', 64);
            $table->timestamp('updated_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_log_reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_log is append-only (% rejected)', TG_OP USING ERRCODE = 'insufficient_privilege';
                END
                $$;
                CREATE TRIGGER audit_log_no_update_delete BEFORE UPDATE OR DELETE ON audit_log
                    FOR EACH ROW EXECUTE FUNCTION audit_log_reject_change();
                CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log
                    FOR EACH STATEMENT EXECUTE FUNCTION audit_log_reject_change();
            SQL);
        } else {
            DB::unprepared("CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only (UPDATE rejected)'");
            DB::unprepared("CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only (DELETE rejected)'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
        Schema::dropIfExists('audit_log');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS audit_log_reject_change()');
        }
    }
};
