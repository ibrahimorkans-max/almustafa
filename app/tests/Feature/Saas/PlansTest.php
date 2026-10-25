<?php

declare(strict_types=1);

use App\Domain\Access\EntityProvisioner;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GateService;
use App\Domain\Saas\Modules;
use App\Domain\Saas\PlanLimits;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\SubscriptionPage;
use App\Filament\Resources\Platform\PlanResource\Pages\ManagePlans;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Plan;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

/** A customer on a plan, with one mill and its Owner. */
function planCustomer(string $code, ?string $plan): array
{
    $customer = Make::customer($code);
    Make::system(fn () => $customer->forceFill(['plan_id' => $plan === null ? null : Plan::query()->where('code', $plan)->value('id')])->save());
    $mill = Make::system(fn () => app(EntityProvisioner::class)->create([
        'code' => $code.'1', 'name' => "Mill {$code}1", 'status' => Entity::STATUS_ACTIVE, 'customer_id' => $customer->id,
    ]));
    $owner = Make::account($mill, 'OWNER', ['mfa' => true]);
    app(PlanLimits::class)->forget();

    return [$customer->fresh(), $mill, $owner];
}

function planSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('keeps every module right a real right', function () {
    foreach (Modules::ALL as $module => [, $rights]) {
        foreach ($rights as $right) {
            expect(PermissionCatalog::exists($right))->toBeTrue("{$module}: {$right}");
        }
    }
});

it('takes away the rights, screens and API of modules the plan does not include', function () {
    [, $mill, $owner] = planCustomer('BASIC', 'basic');
    $hr = Make::account($mill, 'HR_MANAGER');

    expect($owner->hasPermission('gate.entry'))->toBeTrue()
        ->and($owner->hasPermission('hr.employee.view'))->toBeFalse()   // HR is not in Basic
        ->and($owner->hasPermission('sales.view'))->toBeFalse()
        ->and($owner->hasPermission('master.manage'))->toBeTrue()       // in every plan
        ->and($hr->hasPermission('hr.employee.view'))->toBeFalse();

    planSignIn($this, $owner);
    $this->get('/admin/hr/employees')->assertForbidden();
    $this->get('/admin/gate')->assertOk();
});

it('gives a customer without a plan everything, as before', function () {
    [, , $owner] = planCustomer('FREE', null);

    expect($owner->hasPermission('hr.employee.view'))->toBeTrue()->and($owner->hasPermission('sales.view'))->toBeTrue();
});

it('stops a new mill, a new user and a new vehicle once the plan\'s limit is reached', function () {
    [$customer, $mill, $owner] = planCustomer('LIM', 'basic');
    Make::system(fn () => Plan::query()->where('code', 'basic')->update(['max_users' => 3, 'max_vehicles_month' => 2]));
    app(PlanLimits::class)->forget();

    // Mills: Basic allows one.
    app(AuthContextBinder::class)->bind($owner);
    expect(fn () => app(EntityProvisioner::class)->create(['code' => 'LIM2', 'name' => 'Second', 'status' => Entity::STATUS_ACTIVE]))
        ->toThrow(BusinessRuleViolation::class, 'allows 1 mill');

    // Users: the Owner and two more; a party login does not count.
    Make::account($mill, 'SUPERVISOR');
    Make::account($mill, 'ACCOUNTANT');
    expect(fn () => Make::account($mill, 'GATE_OPERATOR'))->toThrow(BusinessRuleViolation::class, 'allows 3 users');
    expect(app(PlanLimits::class)->usage($customer->id)['users'])->toBe(3);

    // Vehicles: two a month (the users limit lifted, as the gate helpers add their own staff).
    Make::system(fn () => Plan::query()->where('code', 'basic')->update(['max_users' => null]));
    app(PlanLimits::class)->forget();
    $f = new GateFixtures($mill);
    $f->as($f->owner);
    $gate = app(GateService::class);
    $gate->arrive(['registration_no' => 'LES 1', 'party_id' => $f->seller->id]);
    $gate->arrive(['registration_no' => 'LES 2', 'party_id' => $f->seller->id]);
    expect(fn () => $gate->arrive(['registration_no' => 'LES 3', 'party_id' => $f->seller->id]))
        ->toThrow(BusinessRuleViolation::class, 'vehicles a month');
});

it('locks the customer while it counts, so two arrivals at once cannot both take the last place', function () {
    [$customer, $mill] = planCustomer('RACE', 'basic');
    Make::system(fn () => Plan::query()->where('code', 'basic')->update(['max_users' => null, 'max_vehicles_month' => 5]));
    app(PlanLimits::class)->forget();
    $f = new GateFixtures($mill);
    $f->as($f->owner);

    $locks = [];
    DB::listen(function ($q) use (&$locks): void {
        if (str_contains(strtolower($q->sql), 'customers') && str_contains(strtolower($q->sql), 'for update')) {
            $locks[] = DB::transactionLevel();
        }
    });
    app(GateService::class)->arrive(['registration_no' => 'LES 9', 'party_id' => $f->seller->id]);

    // The customer's row was locked inside the arrival's own transaction (held until the vehicle is saved).
    expect($locks)->not->toBeEmpty()->and(min($locks))->toBeGreaterThanOrEqual(1);
});

it('lets only the platform admin manage plans, and shows a customer its own subscription', function () {
    [, , $owner] = planCustomer('SUB', 'standard');
    planSignIn($this, $owner);
    Livewire::test(SubscriptionPage::class)->assertSee('Standard')->assertSee('Staff users')->assertSee('HR, payroll &amp; daily wages', false);
    $this->get('/admin/platform/plans')->assertForbidden();

    planSignIn($this, Make::platformAdmin(Make::entity('PLT')));
    Livewire::test(ManagePlans::class)->assertSee('Basic')->assertSee('Pro')
        ->callAction('create', ['code' => 'mini', 'name' => 'Mini', 'price' => '5000', 'max_mills' => 1, 'modules' => ['gate'], 'sort' => 5, 'active' => true])
        ->assertHasNoActionErrors();
    expect(Plan::query()->where('code', 'mini')->sole()->price_paise_monthly)->toBe(500000);
    expect(Customer::query()->where('code', 'SUB')->sole()->plan_id)->not->toBeNull();
});
