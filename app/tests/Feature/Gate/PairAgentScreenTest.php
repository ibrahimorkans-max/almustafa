<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Filament\Resources\DeviceResource\Pages\ListDevices;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\Gate\AgentPairing;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

it('shows the pairing code and the ERP address after Make pairing code', function () {
    $f = new GateFixtures(Make::entity('PSC'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $web = Make::webSession($f->owner);
    $this->actingAs($f->owner, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($f->owner, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
    config(['app.debug' => true]);   // a TypeError in the action must fail the test, not become a silent "page expired"

    Livewire::test(ListDevices::class)
        ->assertActionVisible('pairAgent')
        ->callAction('pairAgent', ['scale_id' => $f->scale->id, 'name' => 'Kanta PC'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(Make::system(fn () => AgentPairing::query()->count()))->toBe(1);
});
