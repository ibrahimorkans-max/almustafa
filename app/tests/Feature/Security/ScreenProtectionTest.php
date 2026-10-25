<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Settings\SettingsService;
use App\Filament\Pages\ScreenProtection;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('SCR');
    Make::reset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function scrSignIn(object $t, LoginAccount $a, ?string $working = null): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a) + ($working !== null ? [EnsureWebAuthSession::WORKING_ENTITY_KEY => $working] : []);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])), null, $working);
}

function scrGuarded(object $t): bool
{
    return str_contains($t->get('/admin')->assertOk()->getContent(), 'js/screen-guard.js');
}

it('is on by default and loads the screen guard on every page', function () {
    scrSignIn($this, Make::account($this->entity, 'GATE_OPERATOR'));

    expect(scrGuarded($this))->toBeTrue();
});

it('lets a mill Super Admin turn it off and on for the mill, but nobody else', function () {
    $admin = Make::account($this->entity, 'SUPER_ADMIN');
    scrSignIn($this, $admin);
    Livewire::test(ScreenProtection::class)->assertOk()->assertDontSee('All mills (Owner)')
        ->set('mill', 'off')->call('saveMill')->assertNotified('Saved for this mill. It applies from the next page opened.');

    $gate = Make::account($this->entity, 'GATE_OPERATOR');
    scrSignIn($this, $gate);
    expect(scrGuarded($this))->toBeFalse();
    $this->get(ScreenProtection::getUrl())->assertForbidden();
    Livewire::test(ScreenProtection::class)->assertForbidden();

    scrSignIn($this, $admin);
    Livewire::test(ScreenProtection::class)->set('mill', 'inherit')->call('saveMill');
    scrSignIn($this, $gate);
    expect(scrGuarded($this))->toBeTrue();
});

it('lets the Owner make it compulsory, which a mill cannot turn off', function () {
    $admin = Make::account($this->entity, 'SUPER_ADMIN');
    scrSignIn($this, $admin);
    Livewire::test(ScreenProtection::class)->set('mill', 'off')->call('saveMill');

    $owner = Make::platformAdmin($this->entity);   // the all-mills value is the platform's
    scrSignIn($this, $owner);
    Livewire::test(ScreenProtection::class)->assertSee('All mills (Owner)')
        ->set('all', 'enforced')->call('saveAll')->assertNotified('Saved for all mills. It applies from the next page opened.');

    scrSignIn($this, Make::account($this->entity, 'GATE_OPERATOR'));
    expect(scrGuarded($this))->toBeTrue();

    scrSignIn($this, $admin);
    Livewire::test(ScreenProtection::class)->assertSee('compulsory')->set('mill', 'off')->call('saveMill')
        ->assertNotified('The Owner has made screen protection compulsory for every mill.');
    expect(app(SettingsService::class)->screenGuard($this->entity->id)['on'])->toBeTrue();
});

it('lets the Owner turn it off for all mills, and a mill on again for itself', function () {
    scrSignIn($this, Make::platformAdmin($this->entity));
    Livewire::test(ScreenProtection::class)->set('all', 'off')->call('saveAll');
    expect(app(SettingsService::class)->screenGuard($this->entity->id)['on'])->toBeFalse();

    $other = Make::entity('SC2');
    scrSignIn($this, Make::account($other, 'SUPER_ADMIN'));
    Livewire::test(ScreenProtection::class)->set('mill', 'on')->call('saveMill');

    expect(app(SettingsService::class)->screenGuard($other->id)['on'])->toBeTrue()
        ->and(app(SettingsService::class)->screenGuard($this->entity->id)['on'])->toBeFalse();
});

it('keeps a mill Super Admin from changing the all-mills choice', function () {
    scrSignIn($this, Make::account($this->entity, 'SUPER_ADMIN'));

    Livewire::test(ScreenProtection::class)->set('all', 'off')->call('saveAll')->assertForbidden();
    expect(app(SettingsService::class)->screenGuard(null)['all'])->toBe('on');
});
