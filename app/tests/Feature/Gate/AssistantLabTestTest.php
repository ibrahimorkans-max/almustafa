<?php

declare(strict_types=1);

use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\QuickForms;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GateService;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Livewire\AssistantChat;
use App\Models\AuthSession;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    config(['ricemill.assistant.api_key' => '']);
    $this->f = new GateFixtures(Make::entity('ALT'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->f->as($this->f->owner);
    $this->lot = app(GateService::class)->arrive(['registration_no' => 'MNA 21 1234', 'sauda_id' => $this->sauda->id, 'bag_count' => 400]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function altSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

/** The lab form with these lines filled ("Label" => value); other blanks left. */
function altFill(array $values): string
{
    $lines = explode("\n", QuickForms::template('lab'));
    foreach ($lines as $i => $line) {
        foreach ($values as $label => $value) {
            if (str_starts_with($line, $label)) {
                $lines[$i] = str_replace(QuickForms::BLANK, $value, $line);
            }
        }
    }

    return implode("\n", $lines);
}

it('gives the lab form with one line per lab test and the vehicles waiting, judges the results, and accepts after Confirm', function () {
    altSignIn($this, Make::account($this->f->entity, 'LAB_SUPERVISOR'));
    $chat = Livewire::test(AssistantChat::class)->assertSee('Lab test')
        ->call('quick', 'lab')->assertSee('1 vehicle waiting for the lab ready')->assertSee('Waiting for the lab')->assertSee('MNA');
    $template = $chat->get('message');
    expect($template)->toStartWith("Lab test\nVehicle (number or token): ____")
        ->toContain('Moisture / نمی (%): ____')->toContain('Broken / ٹوٹا ہوا دانہ (%): ____')->toContain('Decision (accept / reject / later): ____');

    // Urdu digits are fine; the verdict shows before Confirm.
    $chat->set('message', altFill(['Vehicle' => 'MNA-21-1234', 'Moisture' => '۱۵', 'Broken' => '2', 'Decision' => 'Accept']))->call('send')
        ->assertSee('⚠ Moisture')->assertSee('Within grace: can be accepted, with a warning.')->assertSee('Then: accept the lot');
    $chat->call('confirm', count($chat->get('messages')) - 1)->assertSee('lab result saved and accepted');

    expect(LabTest::query()->where('gate_entry_id', $this->lot->id)->sole()->results)->toBe(['moisture' => 1500, 'broken' => 200])
        ->and($this->lot->fresh()->status)->toBe('lab_accepted');
});

it('takes a typed command too, and rejects a lot above a grace limit automatically', function () {
    altSignIn($this, Make::account($this->f->entity, 'LAB_SUPERVISOR'));
    $bot = app(Assistant::class);
    $me = Make::account($this->f->entity, 'LAB_SUPERVISOR');
    altSignIn($this, $me);

    $reply = $bot->ask($me, 'lab MNA-21-1234 moisture 19 broken 3 accept');
    expect($reply->pending['tool'])->toBe('record_lab_test')->and($reply->pending['summary'])->toContain('✗ Moisture')->toContain('REJECTED automatically');

    expect($bot->confirm($me, ['tool' => 'record_lab_test', 'input' => $reply->pending['input']])->text)->toContain('rejected automatically')
        ->and($this->lot->fresh()->status)->toBe('lab_rejected');
});

it('lets the gate operator record the result, leaving the decision to the Lab Supervisor', function () {
    $operator = Make::account($this->f->entity, 'GATE_OPERATOR');
    altSignIn($this, $operator);
    $bot = app(Assistant::class);

    expect(array_keys($bot->quickForms($operator)))->toContain('lab');
    $reply = $bot->ask($operator, altFill(['Vehicle' => 'MNA-21-1234', 'Moisture' => '13', 'Decision' => 'accept']));
    expect($bot->confirm($operator, ['tool' => 'record_lab_test', 'input' => $reply->pending['input']])->text)->toContain('Waiting for the Lab Supervisor')
        ->and($this->lot->fresh()->status)->toBe('arrived');

    expect($bot->ask($operator, altFill(['Vehicle' => 'MNA-21-1234']))->text)->toContain('Still blank: at least one lab result');
});

it('says when no vehicle is waiting for the lab, and offers no blanks', function () {
    $me = Make::account($this->f->entity, 'LAB_SUPERVISOR');
    altSignIn($this, $me);
    app(GateService::class)->recordLabTest($this->lot, ['results' => ['moisture' => 1300]]);
    app(GateService::class)->decideLab($this->lot->fresh(), true);

    $start = app(Assistant::class)->quickStart($me, 'lab');
    expect($start['template'])->toBeNull()->and($start['reply']->text)->toContain('✗ No vehicle waiting for the lab yet');
});
