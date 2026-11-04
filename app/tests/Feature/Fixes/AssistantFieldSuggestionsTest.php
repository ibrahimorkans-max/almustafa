<?php

declare(strict_types=1);

use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\QuickFormOptions;
use App\Domain\Assistant\QuickForms;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GateService;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Livewire\AssistantChat;
use App\Models\AuthSession;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    config(['ricemill.assistant.api_key' => '']);
    $this->f = new GateFixtures(Make::entity('AFS'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->f->as($this->f->owner);
    $this->lot = app(GateService::class)->arrive(['registration_no' => 'MNA 21 1234', 'sauda_id' => $this->sauda->id, 'bag_count' => 400]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function afsSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

it('gives each blank the data that fits it: the vehicles waiting, the sellers, the paddy, the saudas, the choices', function () {
    afsSignIn($this, Make::account($this->f->entity, 'SUPER_ADMIN'));
    $seller = Party::query()->findOrFail($this->sauda->party_id);

    $lab = QuickFormOptions::for('lab');
    expect($lab['Vehicle (number or token)'])->toHaveCount(1)
        ->and($lab['Vehicle (number or token)'][0]['value'])->toBe('MNA211234')->and($lab['Vehicle (number or token)'][0]['label'])->toContain('Token')
        ->and(array_column($lab['Decision (accept / reject / later)'], 'value'))->toBe(['accept', 'reject', 'later'])
        // A lab result has no list: it is typed.
        ->and(array_keys($lab))->not->toContain('Moisture / نمی (%)');

    $sauda = QuickFormOptions::for('sauda');
    expect(array_column($sauda['Seller'], 'value'))->toContain($seller->name)
        ->and($sauda['Paddy'])->not->toBeEmpty();

    $arrival = QuickFormOptions::for('vehicle');
    expect(array_column($arrival['Sauda number (optional)'], 'value'))->toContain($this->sauda->number)
        ->and(collect($arrival['Sauda number (optional)'])->firstWhere('value', $this->sauda->number)['label'])->toContain($seller->name)
        ->and(array_column($arrival['Vehicle number'], 'value'))->toContain('MNA211234');

    expect(array_column(QuickFormOptions::for('party')['Acts as (customer / vendor / farmer / broker / transporter / contractor)'], 'value'))
        ->toContain('customer', 'farmer, vendor');
});

it('registers a vehicle with its number, party, type and item from the "Vehicle arrived" form', function () {
    $me = Make::account($this->f->entity, 'SUPER_ADMIN');
    afsSignIn($this, $me);
    $variety = PaddyVariety::query()->create(['code' => 'K1121', 'name' => 'Super Kernel 1121', 'category' => 'basmati']);
    $seller = Party::query()->findOrFail($this->sauda->party_id);

    $options = QuickFormOptions::for('vehicle');
    expect(array_column($options['Party name'], 'value'))->toContain($seller->name)
        ->and(array_column($options['Type (with jack / without jack)'], 'value'))->toBe(['with jack', 'without jack'])
        ->and(array_column($options['Item (paddy variety)'], 'value'))->toContain('Super Kernel 1121');

    $form = QuickForms::template('vehicle');
    expect($form)->toBe("Vehicle arrived\nVehicle number: ____\nParty name: ____\nType (with jack / without jack): ____\nItem (paddy variety): ____\nSauda number (optional): ____\nBags (optional): ____");
    $bot = app(Assistant::class);
    expect($bot->ask($me, str_replace('Vehicle number: ____', 'Vehicle number: LES-22-505', $form))->text)->toContain('Still blank: Type');

    $filled = "Vehicle arrived\nVehicle number: LES-22-505\nParty name: {$seller->name}\nType (with jack / without jack): Without Jack\nItem (paddy variety): 1121\nSauda number (optional): ____\nBags (optional): 250";
    $reply = $bot->ask($me, $filled);
    expect($reply->pending['summary'])->toBe("Register the arrival of LES22505, party {$seller->name}, Without Jack, item Super Kernel 1121, 250 bags.");
    expect($bot->confirm($me, ['tool' => 'register_vehicle_arrival', 'input' => $reply->pending['input']])->text)->toContain('Arrival registered');

    $entry = GateEntry::query()->where('registration_no', 'LES22505')->sole();
    expect($entry->party_id)->toBe($seller->id)->and($entry->jack_type)->toBe('without_jack')
        ->and($entry->paddy_variety_id)->toBe($variety->id)->and($entry->bag_count)->toBe(250);

    // A name that is not a registered party is kept as typed, as on the gate's form.
    $reply = $bot->ask($me, "Vehicle arrived\nVehicle number: LES-22-506\nParty name: Walk-in Rasheed\nType (with jack / without jack): with jack");
    expect($reply->pending['summary'])->toContain('Walk-in Rasheed (not a registered party: kept as typed)');
    $bot->confirm($me, ['tool' => 'register_vehicle_arrival', 'input' => $reply->pending['input']]);
    expect(GateEntry::query()->where('registration_no', 'LES22506')->sole())->party_id->toBeNull()->party_name->toBe('Walk-in Rasheed')->jack_type->toBe('with_jack');
});

it('sends the choices with the quick form, and clears them once it is sent', function () {
    afsSignIn($this, Make::account($this->f->entity, 'LAB_SUPERVISOR'));

    $chat = Livewire::test(AssistantChat::class)->call('quick', 'lab')
        ->assertSet('message', QuickForms::template('lab'))->assertSee('press one to fill the blank');
    expect($chat->get('suggest'))->toHaveKey('Vehicle (number or token)')
        ->and($chat->get('suggest')['Vehicle (number or token)'][0]['value'])->toBe('MNA211234');

    // What a choice writes in the blank is read like typing it.
    $filled = str_replace('Vehicle (number or token): '.QuickForms::BLANK, 'Vehicle (number or token): MNA211234', $chat->get('message'));
    $filled = str_replace('Moisture / نمی (%): '.QuickForms::BLANK, 'Moisture / نمی (%): 13', $filled);
    $chat->set('message', $filled)->call('send')->assertSet('suggest', [])->assertSee('✓ Moisture');
});
