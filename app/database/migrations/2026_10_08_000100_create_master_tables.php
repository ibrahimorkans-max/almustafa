<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data. Every table: UUID key (client-generated allowed for offline creation),
 * entity_id, version (optimistic lock / sync base version), created_by, archived_at.
 * Weights are decimal(15,3) kilograms, money is integer paise, tax rates are basis points.
 */
return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table): void {
            $table->uuid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        };

        Schema::create('brands', function (Blueprint $table) use ($common) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $common($table);
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('tax_rates', function (Blueprint $table) use ($common) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('name', 60);
            $table->unsignedInteger('sales_tax_rate_bp');   // 1800 = 18 %; 0 = zero-rated / exempt
            $table->boolean('exempt')->default(false);      // Sixth Schedule (exempt) rather than zero-rated
            $table->string('status', 16)->default('active');
            $common($table);
            $table->unique(['entity_id', 'name']);
        });

        Schema::create('godowns', function (Blueprint $table) use ($common) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 16)->default('godown'); // godown | silo | yard | bin
            $table->decimal('capacity_kg', 15, 3)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 16)->default('active');
            $common($table);
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('products', function (Blueprint $table) use ($common) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 30);
            $table->string('name', 160);
            // paddy | rice | by_product | packing | consumable | other
            $table->string('category', 16);
            $table->string('variety', 60)->nullable();
            $table->string('pct_code', 12)->nullable();   // Pakistan Customs Tariff heading, e.g. 1006.3090
            $table->foreignUuid('tax_rate_id')->nullable()->constrained('tax_rates');
            $table->foreignUuid('brand_id')->nullable()->constrained('brands');
            $table->string('sale_unit', 10)->default('kg'); // kg | maund | quintal | ton | bag
            $table->decimal('bag_weight_kg', 15, 3)->nullable();
            $table->boolean('is_stock_item')->default(true);
            $table->string('status', 16)->default('active');
            $common($table);
            $table->unique(['entity_id', 'code']);
        });

        Schema::create('parties', function (Blueprint $table) use ($common) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('code', 30);
            $table->string('name', 160);
            $table->string('legal_name', 200)->nullable();
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_vendor')->default(false);
            $table->boolean('is_broker')->default(false);
            $table->boolean('is_farmer')->default(false);
            $table->boolean('is_transporter')->default(false);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 16)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('province', 40)->nullable();
            // registered (has an STRN) | unregistered
            $table->string('tax_registration', 16)->default('unregistered');
            $table->text('strn')->nullable();            // encrypted
            $table->char('strn_index', 64)->nullable();  // blind index
            $table->text('ntn')->nullable();             // encrypted
            $table->char('ntn_index', 64)->nullable();
            $table->text('cnic')->nullable();            // encrypted
            $table->char('cnic_index', 64)->nullable();
            // FBR Active Taxpayer List: active (filer) | inactive | unknown
            $table->string('atl_status', 10)->default('unknown');
            $table->date('atl_checked_on')->nullable();
            $table->bigInteger('credit_limit_paise')->nullable();
            $table->unsignedSmallInteger('credit_days')->nullable();
            $table->uuid('login_account_id')->nullable(); // broker / agent portal account
            $table->string('status', 16)->default('active'); // active | blocked
            $table->text('notes')->nullable();
            $common($table);
            $table->unique(['entity_id', 'code']);
            $table->unique(['entity_id', 'strn_index']);
            $table->index(['entity_id', 'ntn_index']);
            $table->index(['entity_id', 'cnic_index']);
            $table->index(['entity_id', 'name']);
        });

        Schema::create('party_bank_accounts', function (Blueprint $table) use ($common) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('party_id')->constrained('parties');
            $table->string('account_holder', 160);
            $table->text('account_number');                 // encrypted IBAN (PK.. 24 characters)
            $table->char('account_number_index', 64);       // blind index
            $table->string('account_last4', 4);
            $table->string('bank_code', 4)->nullable();     // from the IBAN, e.g. MEZN
            $table->string('bank_name', 120)->nullable();
            $table->string('branch', 120)->nullable();
            $table->text('raast_id')->nullable();           // encrypted (Raast: usually the mobile number)
            $table->boolean('is_primary')->default(false);
            $table->string('status', 16)->default('active');
            $table->uuid('approval_request_id')->nullable();
            $common($table);
            $table->unique(['party_id', 'account_number_index']);
        });

        Schema::create('drivers', function (Blueprint $table) use ($common) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('name', 120);
            $table->string('phone', 16)->nullable();
            $table->string('licence_no', 30)->nullable();
            $table->date('licence_valid_until')->nullable();
            $table->foreignUuid('transporter_party_id')->nullable()->constrained('parties');
            $table->string('status', 16)->default('active');
            $common($table);
        });

        Schema::create('vehicles', function (Blueprint $table) use ($common) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->string('registration_no', 16); // normalised: LES211234
            $table->string('vehicle_type', 16)->default('truck'); // truck | tractor | trolley | tempo | other
            $table->foreignUuid('owner_party_id')->nullable()->constrained('parties');
            $table->foreignUuid('default_driver_id')->nullable()->constrained('drivers');
            $table->decimal('capacity_kg', 15, 3)->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->string('status', 16)->default('active');
            $common($table);
            $table->unique(['entity_id', 'registration_no']);
        });
    }

    public function down(): void
    {
        foreach (['vehicles', 'drivers', 'party_bank_accounts', 'parties', 'products', 'godowns', 'tax_rates', 'brands'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
