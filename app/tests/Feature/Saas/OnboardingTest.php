<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\LoginService;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Saas\CustomerOnboarding;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Platform\CustomerResource\Pages\ManageCustomers;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Plan;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Make;

const SIGNUP_PASSWORD = 'Paddy-Season-2026!';

function signInFails(string $username, string $password = SIGNUP_PASSWORD): ?AuthenticationFailed
{
    try {
        app(LoginService::class)->verifyPassword($username, $password, '127.0.0.1');
    } catch (AuthenticationFailed $e) {
        return $e;
    }

    return null;
}

function resetAccess(): void
{
    app(CustomerAccess::class)->forget();
    Make::reset();
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config(['ricemill.saas.trial_days' => 14]);
});

it('has no public sign-up: only the platform admin opens a company', function () {
    $this->get('/signup')->assertNotFound();
    $this->post('/signup', ['company' => 'Bismillah Rice Traders'])->assertNotFound();
    $this->get('/admin/login')->assertOk()->assertDontSee('Start a free trial');
});

it('opens a company with a free trial, its first mill and its Owner, who sees only its own company', function () {
    $platform = Make::platformAdmin(Make::entity('PLT'));
    Make::reset();
    $opened = app(CustomerOnboarding::class)->open([
        'company' => 'Bismillah Rice Traders', 'mill_name' => 'Bismillah Rice Mill', 'city' => 'Multan', 'owner_name' => 'Kashif Mehmood',
        'phone' => '03017654321', 'username' => 'bismillah.owner', 'password' => SIGNUP_PASSWORD,
        'plan_id' => Plan::query()->where('code', 'standard')->value('id'), 'status' => Customer::TRIAL,
    ], 'platform');
    expect(Make::system(fn () => DB::table('notifications')->where('notifiable_id', $platform->id)->pluck('data')->implode(' ')))->toContain('New customer: Bismillah Rice Traders');

    $customer = $opened['customer'];
    expect($customer->status)->toBe(Customer::TRIAL)
        ->and($customer->source)->toBe('platform')
        ->and($customer->trialDaysLeft())->toBeIn([13, 14]);

    $mill = Make::system(fn () => Entity::query()->where('customer_id', $customer->id)->sole());
    $owner = Make::system(fn () => LoginAccount::query()->with('role')->where('username', 'bismillah.owner')->sole());
    expect($mill->code)->toBe('BRM')->and($mill->name)->toBe('Bismillah Rice Mill')
        ->and($owner->entity_id)->toBe($mill->id)->and($owner->isOwner())->toBeTrue()->and($owner->isPlatformAdmin())->toBeFalse()
        ->and($owner->customerId())->toBe($customer->id);

    // The new Owner signs in; its first device is trusted (nobody else could approve it), the next one is not.
    expect(signInFails('bismillah.owner'))->toBeNull();
    $devices = app(DeviceService::class);
    $devices->resolveForLogin($owner, new DeviceInfo('first-laptop-0001', 'web', 'Laptop'), '127.0.0.1');
    expect(fn () => $devices->resolveForLogin($owner, new DeviceInfo('second-phone-0002', 'web', 'Phone'), '127.0.0.1'))
        ->toThrow(AuthenticationFailed::class);

    // It sees only its own company.
    app(AuthContextBinder::class)->bind($owner);
    expect(Entity::query()->pluck('id')->all())->toBe([$mill->id]);

    // A username already taken is refused.
    expect(fn () => app(CustomerOnboarding::class)->open(['company' => 'Second', 'owner_name' => 'X', 'username' => 'bismillah.owner', 'password' => SIGNUP_PASSWORD], 'platform'))
        ->toThrow(BusinessRuleViolation::class, 'This username is taken.');
});

it('lets a trial-ended or paused company sign in to pay, refuses a closed one, and never the platform admin', function () {
    $opened = app(CustomerOnboarding::class)->open([
        'company' => 'Trial Mills', 'owner_name' => 'Asad', 'phone' => '03000000001', 'username' => 'trial.owner', 'password' => SIGNUP_PASSWORD,
    ], 'signup');
    resetAccess();
    expect(signInFails('trial.owner'))->toBeNull();

    Make::system(fn () => $opened['customer']->update(['trial_ends_at' => now()->subMinute()]));
    resetAccess();
    expect(signInFails('trial.owner'))->toBeNull();   // it signs in, to the payment page

    Make::system(fn () => $opened['customer']->update(['status' => Customer::SUSPENDED, 'trial_ends_at' => null]));
    resetAccess();
    expect(signInFails('trial.owner'))->toBeNull();

    Make::system(fn () => $opened['customer']->update(['status' => Customer::CANCELLED]));
    resetAccess();
    $e = signInFails('trial.owner');
    expect($e?->publicCode)->toBe(AuthenticationFailed::SUBSCRIPTION_ENDED)->and($e?->publicMessage())->toContain('account is closed');

    // An open web session ends on its next request.
    Make::system(fn () => $opened['customer']->update(['status' => Customer::ACTIVE]));
    resetAccess();
    $owner = Make::system(fn () => LoginAccount::query()->with(['role', 'person', 'entity'])->where('username', 'trial.owner')->sole());
    $session = Make::webSession($owner);
    Make::system(fn () => $opened['customer']->update(['status' => Customer::CANCELLED]));
    resetAccess();
    $this->actingAs($owner, 'web')->withSession($session)->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertSee('account is closed');

    // The platform admin is never shut out, whatever its own company's state.
    $platform = Make::platformAdmin(Make::entity('PLT'));
    Make::system(fn () => Customer::query()->whereKey($platform->customerId())->update(['status' => Customer::SUSPENDED]));
    resetAccess();
    expect(app(CustomerAccess::class)->blocks($platform))->toBeFalse();
});

it('lets the platform admin open, extend, pause and activate customers; nobody else sees them', function () {
    $owner = Make::account(Make::entity('OWN'), 'OWNER', ['mfa' => true]);
    $this->actingAs($owner, 'web')->withSession(Make::webSession($owner));
    app(AuthContextBinder::class)->bind($owner);
    $this->get('/admin/platform/customers')->assertForbidden();

    app('auth')->forgetGuards();
    $this->flushSession();
    $platform = Make::platformAdmin(Make::entity('PLT'));
    $this->actingAs($platform, 'web')->withSession(Make::webSession($platform));
    app(AuthContextBinder::class)->bind($platform);
    $this->get('/admin/platform/customers')->assertOk();

    $basic = Plan::query()->where('code', 'basic')->value('id');
    Livewire::test(ManageCustomers::class)
        ->callAction('open', [
            'company' => 'Al Noor Rice', 'phone' => '0300 5550001', 'plan_id' => $basic, 'status' => Customer::TRIAL, 'trial_days' => 30,
            'mill_name' => 'Al Noor Rice Mill', 'mill_code' => 'ANR', 'city' => 'Lahore',
            'owner_name' => 'Noor Ahmed', 'username' => 'alnoor.owner', 'password' => SIGNUP_PASSWORD,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $customer = Make::system(fn () => Customer::query()->where('name', 'Al Noor Rice')->sole());
    $alnoor = Make::system(fn () => LoginAccount::query()->where('username', 'alnoor.owner')->sole());
    expect($customer->source)->toBe('platform')->and($customer->plan_id)->toBe($basic)
        ->and($customer->trialDaysLeft())->toBeIn([29, 30])
        ->and($alnoor->must_change_password)->toBeTrue()
        ->and(Make::system(fn () => Entity::query()->where('customer_id', $customer->id)->value('code')))->toBe('ANR');

    Livewire::test(ManageCustomers::class)
        ->callTableAction('extendTrial', $customer, ['days' => 5])->assertHasNoTableActionErrors()
        ->assertTableActionVisible('suspend', $customer)
        ->callTableAction('suspend', $customer, ['reason' => 'Asked to pause for the off-season'])->assertNotified('Al Noor Rice is blocked.');
    $customer->refresh();
    expect($customer->status)->toBe(Customer::SUSPENDED);
    resetAccess();
    expect(app(CustomerAccess::class)->blocked(Make::system(fn () => $alnoor->fresh(['role']))))->toBeTrue();   // blocked: signed in, all data hidden

    app(AuthContextBinder::class)->bind($platform);
    Livewire::test(ManageCustomers::class)
        ->assertTableActionVisible('unblock', $customer)->assertTableActionHidden('suspend', $customer)->assertTableActionHidden('activate', $customer)
        ->callTableAction('unblock', $customer)->assertNotified('Al Noor Rice is unblocked.');
    expect($customer->refresh()->status)->toBe(Customer::ACTIVE)->and($customer->trial_ends_at)->toBeNull();
    resetAccess();
    expect(app(CustomerAccess::class)->blocked(Make::system(fn () => $alnoor->fresh(['role']))))->toBeFalse();

    // The platform's own company cannot be paused from here.
    app(AuthContextBinder::class)->bind($platform);
    Livewire::test(ManageCustomers::class)->assertTableActionHidden('suspend', Customer::query()->find($platform->customerId()));
    expect(DB::table('notifications')->where('notifiable_id', $platform->id)->count())->toBe(1);   // told of the new customer
});

it('reminds a customer\'s Owners three days and one day before the trial ends', function () {
    $opened = app(CustomerOnboarding::class)->open([
        'company' => 'Reminder Mills', 'owner_name' => 'Bilal', 'phone' => '03000000002', 'username' => 'remind.owner',
        'password' => SIGNUP_PASSWORD, 'trial_days' => 3,
    ], 'signup');

    $this->artisan('saas:trial-reminders')->expectsOutputToContain('trial ends in 3 days, 1 told')->assertSuccessful();
    expect(DB::table('notifications')->where('notifiable_id', $opened['owner']->id)->count())->toBe(1);

    $this->travel(2)->days();
    $this->artisan('saas:trial-reminders')->expectsOutputToContain('trial ends in 1 day, 1 told')->assertSuccessful();
    $this->travel(1)->days();
    $this->artisan('saas:trial-reminders')->expectsOutputToContain('0 reminders sent')->assertSuccessful();
});
