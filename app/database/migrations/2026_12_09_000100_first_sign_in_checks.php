<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more checks at a new user's first sign-in, one after the other:
 *  - the e-mail carries a 6-digit code, typed on the screen that is signing in (only that sign-in's code, a few tries);
 *  - somebody already signed in who may manage devices approves it: they scan the QR code shown on the new screen
 *    (or open Approve sign-ins) and pick the number that screen shows (App\Domain\Auth\SignInApprovals);
 *  - later, a web sign-in from a network the login may not use is held until it is proven the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_accounts', function (Blueprint $table): void {
            $table->uuid('email_verify_session_id')->nullable();   // the sign-in the e-mail code was sent for
            $table->smallInteger('email_verify_attempts')->default(0);
            $table->timestamp('first_sign_in_approved_at')->nullable();
        });

        Schema::create('sign_in_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('login_account_id')->constrained('login_accounts');
            $table->uuid('auth_session_id')->nullable();
            $table->string('purpose', 16)->default('first_sign_in'); // first_sign_in | new_network
            $table->string('token_hash', 64)->unique();             // the QR code's link (only its hash)
            $table->smallInteger('number');                         // shown on the new screen
            $table->json('choices');                                // offered to the approver, the number among them
            $table->string('status', 12)->default('pending');       // pending | approved | denied
            $table->string('reason', 40)->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->index(['login_account_id', 'status']);
            $table->index(['entity_id', 'status']);
        });
        TenantTables::secure(['sign_in_approvals'], []);

        // A web sign-in from a network the login may not use, held until it is proven (App\Domain\Auth\NewNetwork).
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->timestamp('network_pending_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table): void {
            $table->dropColumn('network_pending_at');
        });
        Schema::dropIfExists('sign_in_approvals');
        Schema::table('login_accounts', function (Blueprint $table): void {
            $table->dropColumn(['email_verify_session_id', 'email_verify_attempts', 'first_sign_in_approved_at']);
        });
    }
};
