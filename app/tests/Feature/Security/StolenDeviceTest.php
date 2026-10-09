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
    $this->owner = Make::account($this->entity, 'OWNER');
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
    // Owners were told.
    expect(DB::table('notifications')->where('notifiable_id', $this->owner->id)->value('data'))->toContain('Left in a taxi');
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

it('slows guessing down after 3 wrong passwords, then locks for 15 minutes', function () {
    tryLogin($this->user, 'wrong-1');
    tryLogin($this->user, 'wrong-2');
    tryLogin($this->user, 'wrong-3');
    $acc = Make::system(fn () => $this->user->fresh());
    expect($acc->locked_until)->not->toBeNull()
        ->and(now()->diffInSeconds($acc->locked_until))->toBeLessThanOrEqual(2.1)
        // Inside the pause even the right password is refused (generic message).
        ->and(tryLogin($this->user, Make::PASSWORD)?->publicCode)->toBe('invalid_credentials');

    $this->travel(3)->seconds();
    tryLogin($this->user, 'wrong-4');
    expect(now()->diffInSeconds(Make::system(fn () => $this->user->fresh()->locked_until)))->toBeGreaterThan(3.0);
    $this->travel(5)->seconds();
    tryLogin($this->user, 'wrong-5');
    $acc = Make::system(fn () => $this->user->fresh());
    expect(now()->diffInMinutes($acc->locked_until))->toBeGreaterThan(14.0)
        ->and($acc->lockouts_24h)->toBe(1)->and($acc->needs_admin_unlock)->toBeFalse();
    expect(DB::table('notifications')->where('notifiable_id', $this->owner->id)->value('data'))->toContain('Account locked after failed sign-ins');
});

it('after 3 lockouts in 24 hours only an administrator can unlock', function () {
    for ($round = 0; $round < 3; $round++) {
        Make::system(fn () => LoginAccount::query()->whereKey($this->user->id)->update(['failed_attempts' => 4, 'locked_until' => null]));
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
