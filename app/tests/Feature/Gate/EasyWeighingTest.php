<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Gate\GateService;
use App\Domain\Gate\LabReport;
use App\Domain\Gate\LiveCapture;
use App\Domain\Gate\LiveWeight;
use App\Domain\Gate\ManualWeight;
use App\Domain\Gate\NegotiationTimeline;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\NegotiationPage;
use App\Filament\Resources\Gate\LabParameterResource\Pages\ManageLabParameters;
use App\Filament\Widgets\LiveWeightBoard;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Livewire\LiveWeightDial;
use App\Models\Attachment;
use App\Models\AuthSession;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabParameter;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('EWG'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->sup = Make::account($this->f->entity, 'SUPERVISOR');
    $this->operator = Make::account($this->f->entity, 'GATE_OPERATOR');
    $this->f->as($this->f->owner);
    $this->silo = Godown::query()->create(['code' => 'S1', 'name' => 'Paddy silo 1', 'type' => 'silo']);
    $this->gate = app(GateService::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    // A 1×1 PNG, as an uploaded weighbridge slip.
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==');
});

function ewgSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

function ewgFeed(object $t, string $kg): void
{
    foreach (range(1, 6) as $_) {
        app(LiveWeight::class)->record(['scale_id' => $t->f->scale->id, 'kg' => $kg, 'stable' => true]);
        $t->travel(1)->seconds();
    }
}

function ewgArrive(object $t, string $plate = 'MNA 21 1234'): GateEntry
{
    return $t->gate->arrive(['registration_no' => $plate, 'sauda_id' => $t->sauda->id, 'bag_count' => 400]);
}

// ------------------------------------------------------------------ tokens

it('gives each arriving vehicle the next token of the day, first come first served, starting again each day', function () {
    $a = ewgArrive($this);
    $b = ewgArrive($this, 'LES 1234');
    expect([$a->token_no, $b->token_no])->toBe([1, 2]);

    $this->travelTo(now('Asia/Karachi')->addDay()->setTime(8, 0));
    expect(ewgArrive($this, 'BKX 777')->token_no)->toBe(1)
        ->and(ewgArrive($this, 'BKX 778')->token_no)->toBe(2);
});

it('keeps tokens in sequence after numbers already given today, never twice the same', function () {
    // A token given before the daily counter existed (an older release): the counter carries on after it.
    $old = ewgArrive($this);
    DB::table('document_sequences')->where('series', 'TOKEN')->delete();
    $old->forceFill(['token_no' => 7])->save();

    $tokens = collect(['LES 1', 'LES 2', 'LES 3'])->map(fn (string $plate) => ewgArrive($this, $plate)->token_no)->all();
    expect($tokens)->toBe([8, 9, 10]);
});

it('prints the gate entry token: mill, token number, party, vehicle, type, date and time, on the small roll', function () {
    $e = ewgArrive($this);
    ewgSignIn($this, $this->f->owner);

    $pdf = $this->get(route('filament.admin.gate.token', ['id' => $e->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    $html = PdfRenderer::$lastHtml;
    expect(PdfRenderer::$lastPaper)->toBe('roll')
        ->and($html)->toContain('GATE ENTRY TOKEN')->toContain('>000001<')->toContain('MNA-21-1234')->toContain('Party Name')->toContain('Time')
        ->and($html)->not->toContain('Rs')
        ->and($pdf)->toContain('/MediaBox [0 0 226.77');   // 80 mm wide
});

// ------------------------------------------------------------------ paper sizes

it('prints every slip and report on A4, A6, Letter or the small roll as chosen', function () {
    $e = ewgArrive($this);
    GateFixtures::passLab($this->gate, $e);
    ewgSignIn($this, $this->f->owner);

    foreach (['a4' => '595.28', 'a6' => '297.64', 'letter' => '612.00', 'roll' => '226.77', 'roll50' => '141.73'] as $paper => $width) {
        $pdf = $this->get(route('filament.admin.gate.token', ['id' => $e->id, 'paper' => $paper]))->assertOk()->getContent();
        expect(PdfRenderer::$lastPaper)->toBe($paper)->and($pdf)->toContain('/MediaBox [0 0 '.$width);
    }

    // The choice is remembered on this device for every later print.
    $this->post(route('filament.admin.print-paper'), ['paper' => 'letter'])->assertNoContent()->assertCookie('print_paper');
    $this->post(route('filament.admin.print-paper'), ['paper' => 'poster'])->assertStatus(422);
});

// ------------------------------------------------------------------ weights

it('records the loaded and the empty weight from the weighbridge, unloading into the godown on the way', function () {
    $e = ewgArrive($this);
    GateFixtures::passLab($this->gate, $e);
    $this->f->as($this->operator);
    ewgFeed($this, '25480');
    app(LiveCapture::class)->record($e->fresh(), $this->f->scale, 'gross');

    $steps = LiveCapture::steps($e->fresh());
    expect($steps['gross']['done'])->toBeTrue()->and($steps['tare']['allowed'])->toBeTrue()->and($steps['unload'])->toBeTrue();
    ewgFeed($this, '10230');
    expect(fn () => app(LiveCapture::class)->record($e->fresh(), $this->f->scale, 'tare'))->toThrow(BusinessRuleViolation::class, 'Choose the godown');

    app(LiveCapture::class)->record($e->fresh(), $this->f->scale, 'tare', $this->silo);
    $e->refresh();
    expect($e->net_kg)->toBe('15250.000')->and($e->godown_id)->toBe($this->silo->id);
});

it('lets a Supervisor type one weight by hand with the reason and the slip, never both', function () {
    $e = ewgArrive($this);
    GateFixtures::passLab($this->gate, $e);
    $this->f->as($this->sup);
    // The loaded weight was taken on another weighbridge: typed, with its slip.
    $w = app(ManualWeight::class)->record($e->fresh(), 'gross', '25480', 'Loaded weight from Kanta 2, slip no. 4512', $this->png);

    expect($w->source)->toBe(Weighment::SOURCE_MANUAL)
        ->and($w->flags)->toContain('manual_entry')->not->toContain('no_slip_evidence')
        ->and($w->readings['evidence_sha256'])->toBe(hash('sha256', $this->png))
        ->and(Attachment::query()->where('attachable_id', $e->id)->where('kind', 'weighbridge_slip')->count())->toBe(1);

    // The empty weight must then come from this weighbridge.
    $this->gate->markUnloaded($e->fresh(), godown: $this->silo);
    expect(fn () => app(ManualWeight::class)->record($e->fresh(), 'tare', '10230', 'Typed as well'))
        ->toThrow(BusinessRuleViolation::class, 'must come from the weighbridge');
    $this->f->as($this->operator);
    ewgFeed($this, '10230');
    app(LiveCapture::class)->record($e->fresh(), $this->f->scale, 'tare');
    expect($e->fresh()->net_kg)->toBe('15250.000');
});

it('keeps typing weights to Supervisors and above, always with a reason', function () {
    $e = ewgArrive($this);
    GateFixtures::passLab($this->gate, $e);
    $this->f->as($this->operator);
    expect(fn () => app(ManualWeight::class)->record($e->fresh(), 'gross', '25480', 'From another kanta'))
        ->toThrow(BusinessRuleViolation::class, 'may not type a weight');
    $this->f->as($this->sup);
    expect(fn () => app(ManualWeight::class)->record($e->fresh(), 'gross', '25480', ''))
        ->toThrow(BusinessRuleViolation::class, 'Give the reason');

    $w = app(ManualWeight::class)->record($e->fresh(), 'gross', '25480', 'Kanta 2 slip lost; figure read by phone');
    expect($w->flags)->toContain('no_slip_evidence');
});

it('shows the weight on a dial with one Record weight button and Enter one weight manually', function () {
    $e = ewgArrive($this);
    GateFixtures::passLab($this->gate, $e);
    ewgSignIn($this, $this->sup);
    $sid = $this->f->scale->id;

    Livewire::test(LiveWeightBoard::class)
        ->assertSee('TONNES')->assertSee('Record weight')->assertSee('Enter one weight manually')
        ->set('plate.'.$sid, 'MNA211234')->assertSee('Record loaded weight (gross)')
        ->callAction('typeWeight', ['kind' => 'gross', 'weight_kg' => '25480', 'reason' => 'Loaded weight from Kanta 2'], ['scale' => $sid])
        ->assertNotified();
    expect($e->fresh()->gross_kg)->toBe('25480.000');
});

it('weighs a new vehicle from party, vehicle number and inbound or outbound, keeping paddy lab first', function () {
    ewgSignIn($this, $this->f->owner);
    $sid = $this->f->scale->id;
    $board = fn () => Livewire::test(LiveWeightBoard::class);

    // Outbound, no dispatch order: empty weight first, then loaded, straight away.
    app(LiveWeight::class)->record(['scale_id' => $sid, 'kg' => '9000', 'stable' => true]);
    ewgFeed($this, '9000');
    $board()->set('plate.'.$sid, 'LES 4455')->set('party.'.$sid, 'Bhatti Traders')->set('kind.'.$sid, 'outbound')
        ->call('recordWeight', $sid)->assertNotified();
    $out = GateEntry::query()->where('registration_no', 'LES4455')->sole();
    expect($out->purpose)->toBe('other')->and($out->tare_kg)->toBe('9000.000')->and($out->party_id)->toBeNull()->and($out->partyLabel())->toBe('Bhatti Traders') // typed, no party record made
        ->and($out->token_no)->toBe(1);
    ewgFeed($this, '24000');
    $board()->set('plate.'.$sid, 'LES 4455')->call('recordWeight', $sid)->assertNotified();
    expect($out->fresh()->net_kg)->toBe('15000.000');
    $this->gate->issueSlip($out->fresh());   // a slip, without stock or a bill

    // Inbound other goods: loaded weight first, no lab.
    $board()->set('plate.'.$sid, 'BKX 777')->set('party.'.$sid, 'Bhatti Traders')->set('kind.'.$sid, 'other_in')
        ->call('recordWeight', $sid)->assertNotified();
    expect(GateEntry::query()->where('registration_no', 'BKX777')->sole()->gross_kg)->toBe('24000.000');

    // Inbound paddy: registered with a token, but weighed only after the lab accepts it.
    $board()->set('plate.'.$sid, 'MNA 9090')->set('party.'.$sid, 'Muhammad Aslam')->set('kind.'.$sid, 'paddy')
        ->call('recordWeight', $sid)->assertNotified('Token 000003 · MNA-9090 registered.');
    $paddy = GateEntry::query()->where('registration_no', 'MNA9090')->sole();
    expect($paddy->gross_kg)->toBeNull()->and($paddy->status)->toBe('arrived');

    // An unregistered name is kept on the visit as typed, for anyone: no party record is made from here.
    $parties = Party::query()->count();
    ewgSignIn($this, $this->operator);
    $this->f->as($this->operator);
    Livewire::test(LiveWeightBoard::class)->set('plate.'.$sid, 'MNA 5151')->set('party.'.$sid, 'Someone New')->set('kind.'.$sid, 'outbound')
        ->call('recordWeight', $sid)->assertNotified();
    expect(GateEntry::query()->where('registration_no', 'MNA5151')->sole()->party_name)->toBe('Someone New')
        ->and(Party::query()->count())->toBe($parties);
});

// ------------------------------------------------------------------ lab

it('lets the lab correct a result with the reason, and decide again on the corrected figures', function () {
    $e = ewgArrive($this);
    GateFixtures::passLab($this->gate, $e, ['results' => ['moisture' => 1500]]);
    expect($e->fresh()->status)->toBe('lab_accepted');

    expect(fn () => $this->gate->recordLabTest($e->fresh(), ['results' => ['moisture' => 2600]]))
        ->toThrow(BusinessRuleViolation::class, 'Give the reason');

    // Corrected above the grace limit: rejected at once, the earlier acceptance no longer counts.
    $this->gate->recordLabTest($e->fresh(), ['results' => ['moisture' => 2600], 'correction_reason' => 'Moisture meter was not calibrated']);
    $e->refresh();
    $report = LabReport::for($e);
    expect($e->status)->toBe('lab_rejected')
        ->and($report['decision'])->toBe('rejected')
        ->and($report['rejected_reason'])->toContain('Moisture')
        ->and($report['corrections'])->toBe(1);

    // Corrected back within the limits: waiting for the lab's decision again.
    $this->gate->recordLabTest($e->fresh(), ['results' => ['moisture' => 1400], 'correction_reason' => 'Second sample from the middle of the load']);
    expect($e->fresh()->status)->toBe('arrived')->and(LabReport::for($e->fresh())['decision'])->toBe('pending');
    $this->gate->decideLab($e->fresh(), true, 'Accepted on the second sample');
    expect($e->fresh()->status)->toBe('lab_accepted');
});

it('sets the lab tests for each product and variety', function () {
    ewgSignIn($this, $this->f->owner);
    $this->f->paddy->update(['variety' => '1121 Kainat']);
    Livewire::test(ManageLabParameters::class)->callAction('copyToProduct', ['product_id' => $this->f->paddy->id])->assertNotified();

    $own = LabParameter::query()->where('product_id', $this->f->paddy->id)->get();
    expect($own)->toHaveCount(LabParameter::query()->whereNull('product_id')->where('status', 'active')->count());
    $own->firstWhere('code', 'moisture')->update(['standard_bp' => 1300, 'grace_bp' => 1500]);

    // This variety's own limit applies to its lots.
    $e = ewgArrive($this);
    $this->gate->recordLabTest($e, ['results' => ['moisture' => 1600]]);
    expect($e->fresh()->status)->toBe('lab_rejected');
});

// ------------------------------------------------------------------ weight bill

it('prints the weight bill with the weights and the lab report, never an amount', function () {
    $e = ewgArrive($this);
    $this->gate->recordLabTest($e, ['results' => ['moisture' => 2600, 'broken' => 300]]);   // rejected: moisture over grace
    $this->gate->proposePrice($e->fresh(), 90000, 'Lower price for the wet lot');
    $this->f->as($this->f->owner2);
    $this->gate->decidePrice($e->fresh(), true, 'Agreed');
    $this->gate->acceptRejectedLot($e->fresh(), 'Agreed at a lower price for the wet lot');
    $this->f->as($this->f->owner);
    $this->gate->recordWeighment($e->fresh(), 'gross', '25480', $this->f->scale, GateFixtures::readings('25480'), str_repeat('a', 64));
    $this->gate->markUnloaded($e->fresh(), godown: $this->silo);
    $this->gate->recordWeighment($e->fresh(), 'tare', '10230', $this->f->scale, GateFixtures::readings('10230'), str_repeat('a', 64));
    $this->f->settle($this->gate, $e->fresh());
    $this->gate->issueSlip($e->fresh());

    app(SlipRenderer::class)->pdf($e->fresh());
    $html = PdfRenderer::$lastHtml;
    expect($html)->toContain('Lab report')->toContain('Moisture')->toContain('OVER GRACE')
        ->toContain('Rejected because')->toContain('Taken in because')->toContain('Agreed at a lower price for the wet lot')
        ->toContain('15,250.000')
        ->not->toContain('Rate cut')->not->toContain('Rs ')->not->toContain('per maund');
});

// ------------------------------------------------------------------ negotiation

it('keeps every price and Caat proposal in the negotiation timeline, on its own page', function () {
    $e = ewgArrive($this);
    GateFixtures::passLab($this->gate, $e);
    $this->gate->proposePrice($e->fresh(), 98000, 'Market is lower today');
    $this->f->as($this->f->owner2);
    $this->gate->decidePrice($e->fresh(), false, 'Too low');
    $this->f->as($this->f->owner);
    $this->gate->proposePrice($e->fresh(), 99000, 'Meet halfway');
    $this->f->as($this->f->owner2);
    $this->gate->decidePrice($e->fresh(), true, 'Agreed');
    $this->f->as($this->f->owner);
    $this->gate->recordWeighment($e->fresh(), 'gross', '25480', $this->f->scale, GateFixtures::readings('25480'), str_repeat('a', 64));
    $this->gate->markUnloaded($e->fresh(), godown: $this->silo);
    $this->gate->recordWeighment($e->fresh(), 'tare', '10230', $this->f->scale, GateFixtures::readings('10230'), str_repeat('a', 64));
    $this->gate->proposeSettlement($e->fresh(), '50', '0.5', 'Some dust');

    $what = array_column(NegotiationTimeline::for($e->fresh()), 'what');
    expect($what)->toContain('Price proposed (version 1)', 'Price rejected (version 1)', 'Price proposed (version 2)', 'Price accepted (version 2)', 'Caat proposed');

    ewgSignIn($this, $this->f->owner2);
    Livewire::test(NegotiationPage::class)->assertSee('Token 000001 · MNA-21-1234')->assertSee('Caat proposed — waiting for approval')
        ->call('choose', $e->id)->assertSee('Meet halfway')->assertSee('Waste Caat 50')
        ->callAction('approveSettlement', ['note' => 'OK'])->assertNotified('Settlement approved');
    expect(app(GateService::class)->settlementApproved($e->fresh()))->toBeTrue();
});

it('shows one clock with the status and the exact weight on its face, switching between weighbridges', function () {
    $second = Scale::query()->create(['code' => 'WB2', 'name' => 'Small kanta', 'capacity_kg' => '300', 'resolution_kg' => '0.1']);
    ewgFeed($this, '25480');
    ewgSignIn($this, $this->f->owner);

    $board = Livewire::test(LiveWeightBoard::class);
    $html = $board->html();
    expect(substr_count($html, '<svg viewBox="0 0 240 240"'))->toBe(1)
        ->and($html)->toContain('25,480')->toContain('STABLE')->toContain('637.00 maund')->toContain('WB1 · TONNES')
        // Only the clock refreshes itself; the form next to it is never redrawn while someone types.
        ->and(substr_count($html, 'wire:poll'))->toBe(1);
    Livewire::test(LiveWeightDial::class, ['scaleId' => $this->f->scale->id])->assertSeeHtml('wire:poll.1s')->assertSee('25,480');

    $html = $board->call('showScale', $second->id)->html();
    expect(substr_count($html, '<svg viewBox="0 0 240 240"'))->toBe(1)
        ->and($html)->toContain('WB2 · KG')->toContain('NO SIGNAL');
});

it('records what the vehicle carries: a product of the list, or the goods as typed', function () {
    ewgSignIn($this, $this->f->owner);
    $sid = $this->f->scale->id;
    ewgFeed($this, '9000');

    Livewire::test(LiveWeightBoard::class)->set('plate.'.$sid, 'LES 7001')->set('party.'.$sid, 'Muhammad Aslam')
        ->set('item.'.$sid, 'paddy 1121')->set('kind.'.$sid, 'other_in')->call('recordWeight', $sid)->assertNotified();
    $listed = GateEntry::query()->where('registration_no', 'LES7001')->sole();
    expect($listed->product_id)->toBe($this->f->paddy->id)->and($listed->itemLabel())->toBe('Paddy 1121');

    Livewire::test(LiveWeightBoard::class)->set('plate.'.$sid, 'LES 7002')->set('party.'.$sid, 'Muhammad Aslam')
        ->set('item.'.$sid, 'Husk, 120 bags')->set('kind.'.$sid, 'outbound')->call('recordWeight', $sid)->assertNotified();
    $typed = GateEntry::query()->where('registration_no', 'LES7002')->sole();
    expect($typed->product_id)->toBeNull()->and($typed->goods_description)->toBe('Husk, 120 bags');

    // Typing the vehicle again brings its item back into the form.
    Livewire::test(LiveWeightBoard::class)->set('plate.'.$sid, 'les-7002')->assertSet('item.'.$sid, 'Husk, 120 bags');
});

it('gives a page whose security token went stale the current one, and only to someone signed in', function () {
    ewgSignIn($this, $this->sup);
    $token = $this->getJson(route('filament.admin.csrf-token'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('token');
    expect($token)->toBe(session()->token());

    app('auth')->forgetGuards();
    $this->flushSession();
    $this->get(route('filament.admin.csrf-token'))->assertRedirect();
});
