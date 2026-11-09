<?php

declare(strict_types=1);

use App\Domain\Messaging\Integrations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One central place for outside services (the SaaS owner's Integrations): the connections stay with the platform;
 * each company or mill gets a sender identity — the provider-approved WhatsApp number and business name, SMS sender
 * or e-mail address its messages go out under (App\Domain\Messaging\SenderIdentities). A mill with none sends
 * nothing on that channel: the platform's own name never stands in for a mill unless the owner assigns it.
 *
 * One WhatsApp number (the platform's business account) sends for every mill; each message carries the mill's own
 * name. A mill that had connected its own number keeps it as its sender (its access token moves to the central
 * encrypted store). Usage records gain the sender they went out under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel', 12);                       // whatsapp | sms | email
            $table->foreignUuid('customer_id')->nullable()->constrained('customers');
            $table->foreignUuid('entity_id')->nullable()->constrained('entities');
            $table->string('provider', 60);
            $table->string('sender', 160);                       // the number, SMS sender ID or e-mail address
            $table->string('display_name', 160);                 // the business name the provider approved
            $table->string('phone_number_id', 40)->nullable();   // WhatsApp
            $table->string('business_account_id', 40)->nullable();
            $table->string('credential_ref', 80)->nullable();    // its own token in the central store, else the platform's
            $table->boolean('platform_sender')->default(false);  // goes out under the platform's own name (chosen on purpose)
            $table->string('language', 10)->default('en');
            $table->string('status', 12)->default('pending');    // approved | pending | inactive
            $table->json('templates')->nullable();               // approved template names (empty: the platform's)
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'customer_id']);
            $table->unique(['channel', 'entity_id']);
        });

        Schema::table('service_usages', function (Blueprint $table) {
            $table->string('sender_identity', 200)->nullable();
        });

        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");
            }
            foreach (DB::table('entities')->get(['id', 'name', 'customer_id']) as $mill) {
                $own = Integrations::whatsappSaved($mill->id);
                $row = null;
                if ($own !== null && filled($own['phone_number_id'] ?? null)) {
                    $row = ['provider' => 'WhatsApp Cloud (Meta)', 'sender' => (string) ($own['business_number'] ?? $own['phone_number_id']),
                        'display_name' => $mill->name, 'phone_number_id' => (string) $own['phone_number_id'],
                        'credential_ref' => 'whatsapp-'.$mill->id, 'platform_sender' => false, 'status' => 'approved',
                        'language' => (string) ($own['language'] ?? 'en')];
                }

                if ($row !== null) {
                    DB::table('sender_identities')->insert($row + ['id' => (string) Str::uuid(), 'channel' => 'whatsapp', 'entity_id' => $mill->id,
                        'customer_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_usages', fn (Blueprint $table) => $table->dropColumn('sender_identity'));
        Schema::dropIfExists('sender_identities');
    }
};
