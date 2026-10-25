<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\LoginService;
use App\Filament\Resources\DeviceResource\Pages\ListDevices;
use App\Filament\Resources\LoginAccountResource\Pages\ListLoginAccounts;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->entity = Make::entity('LST');
    $this->owner = Make::account($this->entity, 'SUPER_ADMIN');
    $this->user = Make::account($this->entity, 'SUPERVISOR');
});

function tryLogin(LoginAccount $a, string $password): ?AuthenticationFailed
{
    try {
        app(LoginService::class)->verifyPassword($a->username, $password, '127.0.0.1');
    } catch (AuthenticationFailed $e) {
        return $e;
    }

    return null;
}

it('marking a phone lost queues the wipe, blocks everything but the wipe, and can sign the user out elsewhere', function () {
    $phone = Make::apiTokens($this->user, 'lost-phone-00000001');
    $other = Make::apiTokens($this->user, 'other-tablet-0000001');

    app(AuthContextBinder::class)->bind($this->owner);
    $device = Make::system(fn () => Device::query()->where('device_uid', 'lost-phone-00000001')->sole());
    app(DeviceService::class)->markLost($device, 'Left in a taxi', signOutEverywhere: true);
    Make::reset();

    expect(Make::system(fn () => $device->fresh()->status))->toBe(Device::STATUS_WIPE_PENDING)
        ->and(Make::system(fn () => $device->fresh()->reported_lost_at))->not->toBeNull();
    // The lost phone can only learn that it must wipe; it reads no data.
    $this->getJson('/api/v1/sync/pull?cursor=0', Make::apiHeaders($phone, 'lost-phone-00000001'))->assertStatus(403)->assertJson(['error' => 'wipe_required']);
    // The user's other device was signed out.
    $this->getJson('/api/v1/auth/me', Make::apiHeaders($other, 'other-tablet-0000001'))->assertUnauthorized();
    // Owners were told (they may also have new-sign-in notices from the second device).
    expect(DB::table('notifications')->where('notifiable_id', $this->owner->id)->pluck('data')->implode(' '))->toContain('Left in a taxi');
});

it('offers Mark lost on the Devices screen', function () {
    Make::apiTokens($this->user, 'lost-phone-00000002');
    $this->actingAs($this->owner, 'web')->withSession(Make::webSession($this->owner));
    app(AuthContextBinder::class)->bind($this->owner);
    $id = Make::system(fn () => Device::query()->where('device_uid', 'lost-phone-00000002')->value('id'));

    Livewire::test(ListDevices::class)
        ->callTableAction('lost', $id, ['reason' => 'Stolen from the gate cabin', 'sign_out_everywhere' => false])
        ->assertNotified('Device marked lost. Wipe queued.');
    Livewire::test(ListDevices::class)->assertTableActionHidden('lost', $id);
});

it('slows guessing down after 3 wrong passwords, then locks out that address for 15 minutes', function () {
    $here = fn () => app(LoginService::class)->addressLockedUntil($this->user, '127.0.0.1');
    tryLogin($this->user, 'wrong-1');
    tryLogin($this->user, 'wrong-2');
    tryLogin($this->user, 'wrong-3');
    expect($here())->not->toBeNull()
        ->and(now()->diffInSeconds($here()))->toBeLessThanOrEqual(2.1)
        // Inside the pause even the right password is refused (generic message).
        ->and(tryLogin($this->user, Make::PASSWORD)?->publicCode)->toBe('invalid_credentials');

    $this->travel(3)->seconds();
    tryLogin($this->user, 'wrong-4');
    expect(now()->diffInSeconds($here()))->toBeGreaterThan(3.0);
    $this->travel(5)->seconds();
    tryLogin($this->user, 'wrong-5');
    $acc = Make::system(fn () => $this->user->fresh());
    expect(now()->diffInMinutes($here()))->toBeGreaterThan(14.0)
        ->and($acc->isLocked())->toBeFalse()->and($acc->needs_admin_unlock)->toBeFalse()
        // The same person, from the mill's own address, is not locked out by the guessing.
        ->and(app(LoginService::class)->verifyPassword($this->user->username, Make::PASSWORD, '10.20.30.40'))->toBeInstanceOf(LoginAccount::class);
    expect(DB::table('notifications')->where('notifiable_id', $this->owner->id)->value('data'))->toContain('Sign-ins blocked from one address');
});

it('after 3 account-wide lockouts in 24 hours only an administrator can unlock', function () {
    $limit = (int) config('ricemill.security.account_lock_after');
    for ($round = 0; $round < 3; $round++) {
        Make::system(fn () => LoginAccount::query()->whereKey($this->user->id)->update(['failed_attempts' => $limit - 1, 'locked_until' => null]));
        tryLogin($this->user, 'wrong');
    }
    $acc = Make::system(fn () => $this->user->fresh());
    expect($acc->needs_admin_unlock)->toBeTrue()->and($acc->lockouts_24h)->toBe(3);

    $this->travel(2)->days(); // waiting does not help
    expect(tryLogin($this->user, Make::PASSWORD)?->publicCode)->toBe('invalid_credentials');

    $this->actingAs($this->owner, 'web')->withSession(Make::webSession($this->owner));
    app(AuthContextBinder::class)->bind($this->owner);
    Livewire::test(ListLoginAccounts::class)->callTableAction('unlock', $this->user->id);
    expect(tryLogin($this->user, Make::PASSWORD))->toBeNull()
        ->and(Make::system(fn () => AuthSession::query()->count()))->toBeGreaterThanOrEqual(1);
});

it('lets an administrator lift an address lock-out too', function () {
    foreach (range(1, 5) as $i) {
        tryLogin($this->user, "wrong-{$i}");
        $this->travel(10)->seconds();
    }
    expect(LoginService::hasAddressLocks($this->user))->toBeTrue();
    $this->actingAs($this->owner, 'web')->withSession(Make::webSession($this->owner));
    app(AuthContextBinder::class)->bind($this->owner);
    Livewire::test(ListLoginAccounts::class)->callTableAction('unlock', $this->user->id);
    expect(LoginService::hasAddressLocks($this->user))->toBeFalse()->and(tryLogin($this->user, Make::PASSWORD))->toBeNull();
});
