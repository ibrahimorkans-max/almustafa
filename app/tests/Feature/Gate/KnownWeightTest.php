<?php

declare(strict_types=1);

use App\Domain\Gate\GatePolicy;
use App\Domain\Gate\GateService;
use App\Domain\Gate\LiveCapture;
use App\Domain\Gate\ManualWeight;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEvent;
use App\Models\Gate\Weighment;
use App\Models\Masters\Godown;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('KWT'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->sup = Make::account($this->f->entity, 'SUPERVISOR');
    $this->gate = app(GateService::class);
    $this->f->as($this->f->owner);
    $this->silo = Godown::query()->create(['code' => 'S1', 'name' => 'Paddy silo 1', 'type' => 'silo']);
    $this->f->as($this->sup);
});

function kwtWeigh(GateService $gate, $entry, string $kind, string $kg): Weighment
{
    return $gate->recordWeighment($entry->fresh(), $kind, $kg, null, null, null);
}

it('takes the empty weight a truck already has at arrival, and uses it after unloading: weighed only once here', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 4100', 'sauda_id' => $this->sauda->id, 'bag_count' => 300]);
    expect(LiveCapture::nextKind($e->fresh()))->toBe('gross');   // weighed as soon as it arrives

    // The empty weight is not its turn yet: noted, nothing recorded.
    expect(app(ManualWeight::class)->noteKnown($e->fresh(), 'tare', '10230', 'Empty weight from Kanta 2, slip 4512'))->toBeNull()
        ->and($e->fresh()->tare_kg)->toBeNull()
        ->and(GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'known_weight_noted')->sole()->data)
        ->toMatchArray(['kind' => 'tare', 'weight_kg' => '10230.000', 'reason' => 'Empty weight from Kanta 2, slip 4512']);

    kwtWeigh($this->gate, $e, 'gross', '25480');
    GateFixtures::passLab($this->gate, $e->fresh());
    $this->gate->markUnloaded($e->fresh(), godown: $this->silo);

    // Unloaded: the noted empty weight is recorded by itself, typed by hand, and the net is ready.
    $e = $e->fresh();
    $tare = ManualWeight::current($e, 'tare');
    expect($e->tare_kg)->toBe('10230.000')->and($e->net_kg)->toBe('15250.000')
        ->and($tare->source)->toBe(Weighment::SOURCE_MANUAL)->and($tare->flags)->toContain('known_weight', 'manual_entry', 'no_slip_evidence')
        ->and(LiveCapture::nextKind($e))->toBeNull()
        ->and(Weighment::query()->where('gate_entry_id', $e->id)->where('kind', 'tare')->count())->toBe(1);
});

it('takes the loaded weight while the lot is still at the lab ("lab first"), and uses it once the lab accepts', function () {
    Make::system(fn () => GatePolicy::set(false, $this->f->entity->id));
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 4200', 'sauda_id' => $this->sauda->id, 'bag_count' => 300]);
    expect(LiveCapture::nextKind($e->fresh()))->toBeNull();   // at the lab

    expect(app(ManualWeight::class)->noteKnown($e->fresh(), 'gross', '25480', 'Loaded weight from the seller\'s kanta, slip 77'))->toBeNull();
    GateFixtures::passLab($this->gate, $e->fresh());

    expect($e->fresh()->gross_kg)->toBe('25480.000')
        ->and(LiveCapture::nextKind($e->fresh()))->toBe('tare');   // the empty weight comes from the weighbridge
});

it('lets the gate operator note it with no reason; it is flagged for the weight checks when used', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 4600', 'sauda_id' => $this->sauda->id, 'bag_count' => 300]);
    $this->f->as(Make::account($this->f->entity, 'GATE_OPERATOR'));
    expect(app(ManualWeight::class)->noteKnown($e->fresh(), 'tare', '10230'))->toBeNull();

    $this->f->as($this->sup);
    kwtWeigh($this->gate, $e, 'gross', '25480');
    GateFixtures::passLab($this->gate, $e->fresh());
    $this->gate->markUnloaded($e->fresh(), godown: $this->silo);

    $tare = ManualWeight::current($e->fresh(), 'tare');
    expect($e->fresh()->net_kg)->toBe('15250.000')->and($tare->reason)->toBeNull()
        ->and($tare->flags)->toContain('known_weight', 'no_reason', 'no_slip_evidence');
});

it('records it at once when it is already the next weight', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 4300', 'sauda_id' => $this->sauda->id]);

    $w = app(ManualWeight::class)->noteKnown($e->fresh(), 'gross', '24000', 'Weighbridge display broken, read on Kanta 2');
    expect($w)->not->toBeNull()->and($e->fresh()->gross_kg)->toBe('24000.000')
        ->and(GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'known_weight_noted')->exists())->toBeFalse();
});

it('keeps the rules: only one typed weight, not twice, not after the visit', function () {
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 4400', 'sauda_id' => $this->sauda->id]);
    app(ManualWeight::class)->noteKnown($e->fresh(), 'tare', '10000', 'Empty weight from Kanta 2');

    // The empty weight was typed, so the loaded one must come from the weighbridge (both only when the Owner allows it).
    expect(fn () => app(ManualWeight::class)->noteKnown($e->fresh(), 'gross', '25000', 'Kanta 2 again'))
        ->toThrow(BusinessRuleViolation::class, 'must come from the weighbridge');

    $done = $this->gate->arrive(['registration_no' => 'MNA 21 4500', 'sauda_id' => $this->sauda->id]);
    kwtWeigh($this->gate, $done, 'gross', '25000');
    expect(fn () => app(ManualWeight::class)->noteKnown($done->fresh(), 'gross', '25000', 'From Kanta 2 slip'))
        ->toThrow(BusinessRuleViolation::class, 'already recorded');

    $this->gate->cancel($done->fresh(), 'Wrong vehicle');
    expect(fn () => app(ManualWeight::class)->noteKnown($done->fresh(), 'tare', '10000', 'From Kanta 2 slip'))->toThrow(BusinessRuleViolation::class);
});
