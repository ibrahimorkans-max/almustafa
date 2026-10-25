<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SaaS plans: what a customer may use — mills, staff users, vehicles a month and modules
 * (App\Domain\Saas\Modules) — and the monthly price. A limit left empty is unlimited. A customer with
 * no plan keeps everything (as before SaaS). Three plans are made to start with; the platform admin
 * changes them. The first customer starts on Pro, so nothing it uses goes away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_paise_monthly')->default(0);
            $table->unsignedInteger('max_mills')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_vehicles_month')->nullable();
            $table->json('modules');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('plan_id')->nullable()->after('status');
            $table->timestamp('trial_ends_at')->nullable()->after('plan_id');
        });

        $now = now();
        $plans = [
            ['basic', 'Basic', 'One mill: gate and weighbridge, procurement, stock and accounts.', 1_500_000, 1, 5, 300,
                ['gate', 'procurement', 'stock', 'accounts'], 10],
            ['standard', 'Standard', 'Up to three mills, with sales and GST, claims and pricing, and party accounts and messages.', 3_500_000, 3, 20, 1500,
                ['gate', 'procurement', 'stock', 'accounts', 'sales', 'claims', 'messages'], 20],
            ['pro', 'Pro', 'Everything, including HR, payroll and daily wages, with no limits.', 6_000_000, null, null, null,
                ['gate', 'procurement', 'stock', 'accounts', 'sales', 'claims', 'hr', 'messages'], 30],
        ];
        foreach ($plans as [$code, $name, $description, $price, $mills, $users, $vehicles, $modules, $sort]) {
            DB::table('plans')->insert([
                'id' => (string) Str::uuid7(), 'code' => $code, 'name' => $name, 'description' => $description,
                'price_paise_monthly' => $price, 'max_mills' => $mills, 'max_users' => $users, 'max_vehicles_month' => $vehicles,
                'modules' => json_encode($modules), 'active' => true, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $pro = DB::table('plans')->where('code', 'pro')->value('id');
        DB::table('customers')->whereNull('plan_id')->update(['plan_id' => $pro]);
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['plan_id', 'trial_ends_at']));
        Schema::dropIfExists('plans');
    }
};
