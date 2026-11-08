<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Filament\Pages\LiveWeightPage;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('W3D'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function w3dSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

it('shows the 3D weighbridge on the Live weight screen, reading this weighbridge, with three.js served by the app', function () {
    w3dSignIn($this, Make::account($this->f->entity, 'GATE_OPERATOR'));

    $html = $this->get(LiveWeightPage::getUrl())->assertOk()
        ->assertSee('data-wb3d-root', false)
        ->assertSee('LIVE')->assertSee('SIMULATION')
        ->assertSee('Vehicle number')->assertSee('Party name')->assertSee('Item / goods')->assertSee('Inbound — paddy')->assertSee('Record weight')
        ->assertDontSee('lab first')
        ->assertSee('data-wb3d-live="'.route('filament.admin.gate.live-weight', ['scale' => $this->f->scale->id]).'"', false)
        ->assertSee('data-scale-code="'.$this->f->scale->code.'"', false)
        ->assertSee('js/vendor/three-0.169.0.module.min.js', false)
        ->assertSee('js/weighbridge-3d.js', false)
        ->assertSee('WEIGHT SLIP')
        ->getContent();

    // The panel is left alone by Livewire, and nothing is loaded from outside the app.
    expect($html)->toMatch('/<div class="mnx-wb3d-stage" wire:ignore wire:key="wb3d-/')->and($html)->toContain('data-wb3d-root')
        ->and($html)->not->toContain('<svg viewBox="0 0 240 240"')   // only the 3D view, no dial
        ->and(file_exists(public_path('js/vendor/three-0.169.0.module.min.js')))->toBeTrue()
        ->and(file_exists(public_path('js/vendor/three-0.169.0-OrbitControls.js')))->toBeTrue()
        ->and(file_exists(public_path('js/vendor/three-0.169.0-RoomEnvironment.js')))->toBeTrue()
        ->and(file_get_contents(public_path('js/weighbridge-3d.js')))->not->toContain('https://')
        ->toContain('never recorded')
        // Drivers sit in the cabs, and a vehicle number typed in the form calls the vehicle onto the weighbridge.
        ->toContain('person(unit')->toContain('readForm()')->toContain('called to the weighbridge');
});

it('draws the profile picture in the app (never from ui-avatars.com) and shows the role with its animated icon', function () {
    w3dSignIn($this, Make::account($this->f->entity, 'GATE_OPERATOR'));

    $this->get(LiveWeightPage::getUrl())->assertOk()
        ->assertDontSee('ui-avatars.com', false)
        ->assertSee('data:image/svg+xml;base64,', false)
        ->assertSee('Weighbridge / Gate Operator')
        ->assertSee('data-role-look="drive"', false);
});
