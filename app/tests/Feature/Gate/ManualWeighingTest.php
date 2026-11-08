<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GateService;
use App\Domain\Gate\ManualWeighing;
use App\Domain\Gate\ManualWeight;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\LiveWeightPage;
use App\Filament\Pages\ManualWeighingPage;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('MWG'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->sup = Make::account($this->f->entity, 'SUPERVISOR');
    $this->operator = Make::account($this->f->entity, 'GATE_OPERATOR');
    $this->gate = app(GateService::class);
    $this->f->as($this->f->owner);
    $this->silo = Godown::query()->create(['code' => 'S1', 'name' => 'Paddy silo 1', 'type' => 'silo']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function mwgSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

it('lets only the Owner choose, with checkboxes, who may type weights and whether both may be typed', function () {
    mwgSignIn($this, $this->sup);
    expect(ManualWeighingPage::canAccess())->toBeFalse();
    $this->get(ManualWeighingPage::getUrl())->assertForbidden();

    $owner = Make::account($this->f->entity, 'OWNER');
    mwgSignIn($this, $owner);
    $this->get(ManualWeighingPage::getUrl())->assertOk()->assertSee('Who may type weights');
    // As before by default: on, one weight only, by role.
    Livewire::test(ManualWeighingPage::class)->assertSet('data.enabled', true)->assertSet('data.both', false)->assertSet('data.users', [])
        ->assertSee($this->operator->username)
        ->set('data.both', true)->set('data.users', [$this->operator->id])->call('save')->assertNotified();

    expect(ManualWeighing::bothAllowed($this->f->entity->id))->toBeTrue()->and(ManualWeighing::users($this->f->entity->id))->toBe([$this->operator->id])
        ->and(ManualWeighing::allowed($this->operator))->toBeTrue()
        ->and(ManualWeighing::allowed($this->sup))->toBeFalse();   // only the people ticked
});

it('lets the people ticked type both weights when the Owner allows it, and nobody when it is off', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 7777', 'sauda_id' => $this->sauda->id, 'bag_count' => 300]);
    Make::system(fn () => ManualWeighing::save($this->f->entity->id, true, true, [$this->operator->id]));

    $this->f->as($this->operator);
    app(ManualWeight::class)->record($e->fresh(), 'gross', '25480', 'Weighbridge out of order, read on Kanta 2');
    $this->f->as($this->sup);
    GateFixtures::passLab($this->gate, $e->fresh());
    $this->gate->markUnloaded($e->fresh(), godown: $this->silo);
    $this->f->as($this->operator);
    app(ManualWeight::class)->record($e->fresh(), 'tare', '10230', 'Weighbridge out of order, read on Kanta 2');
    expect($e->fresh()->net_kg)->toBe('15250.000');

    // The Supervisor is not ticked, so may not.
    $other = $this->gate->arrive(['registration_no' => 'MNA 21 8888', 'sauda_id' => $this->sauda->id]);
    $this->f->as($this->sup);
    expect(fn () => app(ManualWeight::class)->record($other->fresh(), 'gross', '20000', 'From Kanta 2'))->toThrow(BusinessRuleViolation::class, 'may not type a weight');

    // Off: nobody, and the button is not on the weighing screen.
    Make::system(fn () => ManualWeighing::save($this->f->entity->id, false, false, []));
    $this->f->as($this->f->owner);
    expect(fn () => app(ManualWeight::class)->record($other->fresh(), 'gross', '20000', 'From Kanta 2'))->toThrow(BusinessRuleViolation::class, 'cannot be typed by hand');
    mwgSignIn($this, $this->f->owner);
    $this->get(LiveWeightPage::getUrl())->assertOk()->assertDontSee("mountAction('typeWeight'", false);
});

it('keeps "one weight only" unless the Owner allows both', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 9999', 'sauda_id' => $this->sauda->id]);
    $this->f->as($this->sup);
    app(ManualWeight::class)->record($e->fresh(), 'gross', '25480', 'Loaded weight from Kanta 2');
    GateFixtures::passLab($this->gate, $e->fresh());
    $this->gate->markUnloaded($e->fresh(), godown: $this->silo);
    expect(fn () => app(ManualWeight::class)->record($e->fresh(), 'tare', '10230', 'Typed as well'))
        ->toThrow(BusinessRuleViolation::class, 'must come from the weighbridge');
});
