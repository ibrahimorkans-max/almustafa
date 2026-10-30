<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GateService;
use App\Filament\Pages\LiveWeightPage;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ListGateEntries;
use App\Filament\Support\QuickLabTest;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('QLT'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->gate = app(GateService::class);
    $this->f->as($this->f->owner);
    $this->lot = $this->gate->arrive(['registration_no' => 'MNA 21 1234', 'sauda_id' => $this->sauda->id, 'bag_count' => 400]);
    $this->goods = $this->gate->arrive(['registration_no' => 'LES 7001', 'purpose' => 'other', 'goods_description' => 'Rice']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function qltSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

it('lists only paddy lots waiting for the lab, by token, and accepts one in a single step', function () {
    qltSignIn($this, Make::account($this->f->entity, 'LAB_SUPERVISOR'));
    expect(QuickLabTest::waiting())->toHaveKey($this->lot->id)->not->toHaveKey($this->goods->id)
        ->and(QuickLabTest::waiting()[$this->lot->id])->toContain('MNA')->toContain('Token');

    Livewire::test(ListGateEntries::class)->assertActionVisible('quickLabTest')
        ->mountAction('quickLabTest')
        ->setActionData(['entry' => $this->lot->id, 'r_moisture' => '15', 'r_broken' => '2', 'decision' => 'accept'])
        ->assertActionDataSet(['entry' => $this->lot->id])
        ->assertSee('Within grace: can be accepted, with a warning.')
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified();

    $test = LabTest::query()->where('gate_entry_id', $this->lot->id)->sole();
    expect($test->results)->toBe(['moisture' => 1500, 'broken' => 200])
        ->and($this->lot->fresh()->status)->toBe('lab_accepted')
        ->and(QuickLabTest::waiting())->not->toHaveKey($this->lot->id);
});

it('rejects a lot above a grace limit automatically, even if "accept" was chosen', function () {
    qltSignIn($this, Make::account($this->f->entity, 'LAB_SUPERVISOR'));

    Livewire::test(ListGateEntries::class)->mountAction('quickLabTest')
        ->setActionData(['entry' => $this->lot->id, 'r_moisture' => '19', 'decision' => 'accept'])
        ->assertSee('REJECTED automatically')
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified();

    expect($this->lot->fresh()->status)->toBe('lab_rejected');
});

it('lets a lab technician record the result, leaving the decision to the Lab Supervisor', function () {
    qltSignIn($this, Make::account($this->f->entity, 'LAB_TECHNICIAN'));

    Livewire::test(ListGateEntries::class)->mountAction('quickLabTest')
        ->setActionData(['entry' => $this->lot->id, 'r_moisture' => '13'])
        ->assertDontSee('Accept the lot: it can be weighed')
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified();

    expect($this->lot->fresh()->status)->toBe('arrived')
        ->and(LabTest::query()->where('gate_entry_id', $this->lot->id)->exists())->toBeTrue()
        ->and(QuickLabTest::waiting()[$this->lot->id])->toContain('result recorded, waiting for decision');
});

it('needs a reason to reject, and refuses a result that is not a number', function () {
    qltSignIn($this, Make::account($this->f->entity, 'LAB_SUPERVISOR'));

    Livewire::test(ListGateEntries::class)->mountAction('quickLabTest')
        ->setActionData(['entry' => $this->lot->id, 'r_moisture' => 'abc', 'decision' => 'reject', 'note' => ''])
        ->callMountedAction()->assertHasActionErrors(['r_moisture' => 'regex', 'note' => 'required']);

    expect(LabTest::query()->where('gate_entry_id', $this->lot->id)->exists())->toBeFalse();
});

it('shows the button on the gate list and on Live weight only to someone with the lab right', function () {
    // Lab staff open Vehicles at gate (the lab right lets them see it); Live weight is the weighbridge's screen.
    qltSignIn($this, Make::account($this->f->entity, 'LAB_TECHNICIAN'));
    $this->get(ListGateEntries::getUrl())->assertOk()->assertSee('Lab test');
    qltSignIn($this, Make::account($this->f->entity, 'SUPERVISOR'));
    Livewire::test(LiveWeightPage::class)->assertActionVisible('quickLabTest');

    qltSignIn($this, Make::account($this->f->entity, 'GATE_OPERATOR'));
    Livewire::test(ListGateEntries::class)->assertActionHidden('quickLabTest');
});
