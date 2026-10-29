<?php

declare(strict_types=1);

use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Gate\GateService;
use App\Domain\Saas\DataVisibility;
use App\Domain\Security\EntryLocation;
use App\Domain\Settings\SettingsService;
use App\Filament\Pages\CodeDirectoryPage;
use App\Filament\Pages\MyDevicesPage;
use App\Filament\Resources\Platform\DataVisibilityRuleResource\Pages\ManageDataVisibilityRules;
use App\Models\DataVisibilityRule;
use App\Models\Device;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

/*
 * Phase 4 of the October 2026 list: device registration and limits, the installed app, location kept
 * with entries, export rights and watermarks, and the product owner's data visibility windows.
 */
beforeEach(function () {
    // Pakistan dates against now(): at midday both clocks give the same day, whenever the tests are started.
    $this->travelTo(now('Asia/Karachi')->setTime(12, 0));
    $this->f = new GateFixtures(Make::entity('PH4'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->customerId = $this->f->entity->customer_id;
    $this->f->as($this->f->owner);
});

function ph4SignIn(object $t, LoginAccount $account): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

it('refuses a new device once the person uses as many as the mill allows, and never limits the product owner', function () {
    $clerk = Make::account($this->f->entity, 'GATE_OPERATOR');
    app(SettingsService::class)->put(SettingsService::MAX_DEVICES, 2, $this->f->entity->id);
    $devices = app(DeviceService::class);
    $devices->resolveForLogin($clerk, new DeviceInfo('11111111-1111-4111-8111-111111111111', 'web'), '10.0.0.1');
    $devices->resolveForLogin($clerk, new DeviceInfo('22222222-2222-4222-8222-222222222222', 'web'), '10.0.0.1');

    expect(fn () => $devices->resolveForLogin($clerk, new DeviceInfo('33333333-3333-4333-8333-333333333333', 'web'), '10.0.0.1'))
        ->toThrow(fn (AuthenticationFailed $e) => expect($e->publicCode)->toBe(AuthenticationFailed::DEVICE_LIMIT));
    // A known device still signs in.
    expect($devices->resolveForLogin($clerk, new DeviceInfo('11111111-1111-4111-8111-111111111111', 'web'), '10.0.0.1'))->toBeInstanceOf(Device::class);

    // Removing one makes room.
    $devices->revoke(Device::query()->where('device_uid', '22222222-2222-4222-8222-222222222222')->sole(), 'old phone');
    expect($devices->resolveForLogin($clerk, new DeviceInfo('33333333-3333-4333-8333-333333333333', 'web'), '10.0.0.1')->status)->toBe(Device::STATUS_ACTIVE);
});

it('registers the installed app as such, and lists a person\'s own devices with a registration id', function () {
    $owner = $this->f->owner;
    $device = app(DeviceService::class)->resolveForLogin($owner, new DeviceInfo('44444444-4444-4444-8444-444444444444', 'web', 'Chrome'), '10.0.0.2');
    app(DeviceService::class)->resolveForLogin($owner, new DeviceInfo('44444444-4444-4444-8444-444444444444', 'pwa', 'Installed app · Chrome'), '10.0.0.2');
    expect($device->fresh()->platform)->toBe('pwa');
    $other = Make::account($this->f->entity, 'GATE_OPERATOR');
    $theirs = app(DeviceService::class)->resolveForLogin($other, new DeviceInfo('55555555-5555-4555-8555-555555555555', 'web'), '10.0.0.3');

    ph4SignIn($this, $owner);
    Livewire::test(MyDevicesPage::class)->assertOk()
        ->assertCanSeeTableRecords([$device])->assertCanNotSeeTableRecords([$theirs])
        ->assertSee(MyDevicesPage::registrationId($device))->assertSee('Installed app')
        ->callTableAction('remove', $device)->assertNotified('Device removed.');
    expect($device->fresh()->status)->toBe(Device::STATUS_REVOKED);
});

it('keeps where an entry was made only when the mill records locations', function () {
    $gate = app(GateService::class);
    EntryLocation::hold(['loc_lat' => '29.53', 'loc_lng' => '71.63', 'loc_acc' => '12']);
    expect($gate->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack'])->captured_location)->toBeNull();

    app(SettingsService::class)->put(SettingsService::RECORD_LOCATION, 'on', $this->f->entity->id);
    EntryLocation::hold(['loc_lat' => '29.53', 'loc_lng' => '71.63', 'loc_acc' => '12']);
    $located = $gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'with_jack'])->fresh();
    expect($located->captured_location)->toMatchArray(['lat' => 29.53, 'lng' => 71.63, 'accuracy_m' => 12])
        ->and(EntryLocation::mapUrl($located->captured_location))->toContain('openstreetmap.org');

    // A refused position (nothing sent) records nothing.
    EntryLocation::hold([]);
    expect($gate->arrive(['registration_no' => 'LEB1234', 'jack_type' => 'with_jack'])->captured_location)->toBeNull();
});

it('lets only people with the export right download reports and print lists', function () {
    $gateOperator = Make::account($this->f->entity, 'GATE_OPERATOR', ['mfa' => true]);
    DB::table('role_permissions')->insertOrIgnore(['role_id' => $gateOperator->role_id, 'permission_code' => 'master.view', 'entity_id' => $this->f->entity->id, 'created_at' => now()]);
    $gateOperator->refresh();
    ph4SignIn($this, $gateOperator);
    Livewire::test(CodeDirectoryPage::class)->assertActionHidden('excel')->assertActionHidden('pdf')
        ->call('printScreen')->assertForbidden();

    ph4SignIn($this, $this->f->owner);
    expect($this->f->owner->hasPermission('report.export'))->toBeTrue();
    Livewire::test(CodeDirectoryPage::class)->assertActionVisible('excel')->callAction('excel')->assertFileDownloaded('code-directory.xlsx');
});

it('shows a customer only its visibility window, and everything again when extended or overridden', function () {
    $gate = app(GateService::class);
    $old = $gate->arrive(['registration_no' => 'MNA211234', 'jack_type' => 'with_jack'], occurredAt: now()->subDays(60));
    $recent = $gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'with_jack'], occurredAt: now()->subDays(5));
    $rule = Make::system(fn () => DataVisibilityRule::query()->create(['customer_id' => $this->customerId, 'module' => 'gate', 'duration' => 30, 'duration_unit' => 'days']));

    ph4SignIn($this, $this->f->owner);
    // Through real requests: the window is switched on by the panel's middleware (also for Livewire updates).
    $list = fn () => $this->get('/admin/gate?tableFilters[open][value]=all')->assertOk();
    $list()->assertSee('LEA4567')->assertDontSee('MNA211234');
    $this->get('/admin/gate/'.$old->id)->assertNotFound();
    $this->get('/admin')->assertSee('are not shown on your plan');

    // Outside the request (the books, the scheduled jobs) nothing is limited; nothing is deleted.
    expect(GateEntry::query()->count())->toBe(2);

    Make::system(fn () => $rule->update(['override_until' => now()->addDays(3)->toDateString(), 'override_reason' => 'Audit']));
    $list()->assertSee('LEA4567')->assertSee('MNA211234');

    Make::system(fn () => $rule->update(['override_until' => null, 'expires_on' => now()->subDays(10)->toDateString(), 'grace_days' => 5]));
    $list()->assertDontSee('LEA4567')->assertDontSee('MNA211234');
    $this->get('/admin')->assertSee('access has ended');
});

it('uses the plan\'s rule unless the customer has its own, and clamps report periods', function () {
    $plan = Make::system(fn () => Plan::query()->create(['code' => 'BASIC4', 'name' => 'Basic', 'price_paise_monthly' => 0, 'modules' => [], 'active' => true]));
    Make::system(fn () => DB::table('customers')->where('id', $this->customerId)->update(['plan_id' => $plan->id]));
    Make::system(fn () => DataVisibilityRule::query()->create(['plan_id' => $plan->id, 'module' => 'all', 'duration' => 3, 'duration_unit' => 'months']));
    DataVisibility::forget();

    $today = CarbonImmutable::parse('2026-10-29', 'Asia/Karachi');
    expect(DataVisibility::limit($this->customerId, 'accounts', $today)?->toDateString())->toBe('2026-07-29');

    Make::system(fn () => DataVisibilityRule::query()->create(['customer_id' => $this->customerId, 'module' => 'accounts', 'duration' => 10, 'duration_unit' => 'days']));
    expect(DataVisibility::limit($this->customerId, 'accounts', $today)?->toDateString())->toBe('2026-10-19')
        ->and(DataVisibility::limit($this->customerId, 'gate', $today)?->toDateString())->toBe('2026-07-29');

    DataVisibility::applyFor($this->customerId);
    expect(DataVisibility::clampFrom('accounts', '2026-01-01'))->toBe(DataVisibility::limitNow('accounts')->toDateString());
    DataVisibility::stop();
    expect(DataVisibility::clampFrom('accounts', '2026-01-01'))->toBe('2026-01-01');
});

it('lets the product owner set, extend and restore a visibility rule', function () {
    $admin = Make::platformAdmin($this->f->entity);
    ph4SignIn($this, $admin);
    Livewire::test(ManageDataVisibilityRules::class)
        ->callAction('create', ['customer_id' => $this->customerId, 'module' => 'all', 'status' => 'active', 'duration' => 90, 'duration_unit' => 'days',
            'expires_on' => now()->addDays(5)->toDateString(), 'grace_days' => 2])
        ->assertHasNoActionErrors();
    $rule = DataVisibilityRule::query()->sole();
    Livewire::test(ManageDataVisibilityRules::class)->callTableAction('extend', $rule, ['days' => 30]);
    expect($rule->fresh()->expires_on->toDateString())->toBe(now('Asia/Karachi')->addDays(35)->toDateString());
    Livewire::test(ManageDataVisibilityRules::class)->callTableAction('restore', $rule);
    expect($rule->fresh()->status)->toBe('inactive');
});
