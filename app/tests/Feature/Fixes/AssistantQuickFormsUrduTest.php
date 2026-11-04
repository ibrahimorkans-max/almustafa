<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\Roznamcha;
use App\Domain\Accounts\RoznamchaOcr;
use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\Prerequisites;
use App\Domain\Assistant\QuickForms;
use App\Domain\Auth\AuthContextBinder;
use App\Filament\Pages\AssistantPage;
use App\Filament\Resources\Masters\PartyResource;
use App\Filament\Resources\Masters\PartyResource\Pages\CreateParty;
use App\Filament\Resources\Procurement\SaudaResource;
use App\Filament\Resources\Procurement\SaudaResource\Pages\CreateSauda;
use App\Livewire\AssistantChat;
use App\Models\Accounts\LedgerAccount;
use App\Models\Assistant\AssistantChat as SavedChat;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Support\Values\UrduDigits;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    config(['ricemill.assistant.api_key' => '']);
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function qfSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

/** The template with its blanks filled, in order. */
function qfFill(string $key, array $values): string
{
    $out = QuickForms::template($key);
    foreach ($values as $v) {
        $out = preg_replace('/'.preg_quote(QuickForms::BLANK, '/').'/', $v, $out, 1);
    }

    return $out;
}

describe('the floating chat and its quick buttons', function () {
    beforeEach(function () {
        $this->entity = Make::entity('QFA');
        Make::reset();
        $this->admin = Make::account($this->entity, 'SUPER_ADMIN');
    });

    it('is on every screen, with the quick buttons, but not twice on the Assistant page', function () {
        qfSignIn($this, $this->admin);

        $this->get('/admin')->assertOk()->assertSeeLivewire(AssistantChat::class)->assertSee('mnx-assistant-fab', false);
        $this->get(AssistantPage::getUrl())->assertOk()->assertDontSeeLivewire(AssistantChat::class)->assertSee('Add new party');
    });

    it('shows what is needed first, writes the message with blanks, and adds the party after Confirm', function () {
        qfSignIn($this, $this->admin);
        $chat = Livewire::test(AssistantChat::class)->assertSee('Add new party')->assertSee('Vehicle arrived')
            ->call('quick', 'party')
            ->assertSet('message', QuickForms::template('party'))
            ->assertDispatched('assistant-template')
            ->assertSee('before you start')->assertSee('The code is given automatically.');

        // A required blank left: the assistant says which.
        $chat->set('message', qfFill('party', ['Haji Aslam']))->call('send')->assertSee('Still blank: Acts as');

        $chat->set('message', qfFill('party', ['Haji Aslam', 'farmer, vendor', '0300-1234567', 'Lodhran']))->call('send')
            ->assertSee('Add party "Haji Aslam" as Vendor, Farmer, mobile 0300-1234567, Lodhran.');
        $index = count($chat->get('messages')) - 1;
        $chat->call('confirm', $index)->assertSee('Party added');

        $party = Party::query()->where('name', 'Haji Aslam')->sole();
        expect($party->is_farmer)->toBeTrue()->and($party->is_vendor)->toBeTrue()->and($party->is_customer)->toBeFalse()
            ->and($party->phone)->toBe('+923001234567')->and($party->city)->toBe('Lodhran')->and($party->code)->not->toBeEmpty();
    });

    it('keeps the conversation from screen to screen, and each day\'s chats in History to carry on with', function () {
        qfSignIn($this, $this->admin);
        Livewire::test(AssistantChat::class)->set('message', 'help')->call('send');

        $page = Livewire::test(AssistantPage::class)->assertSee('Type one of these commands')
            ->call('clearChat')->assertDontSee('Type one of these commands');
        // "New chat" holds on the next screen until its first question; the chat before is in History.
        Livewire::test(AssistantChat::class)->assertDontSee('Type one of these commands')
            ->call('showTab', 'history')->assertSee('Today')->assertSee('help')->assertSee('3 messages');
        $page->set('message', 'vehicles inside')->call('send');

        $chats = SavedChat::query()->orderBy('created_at')->get();
        expect($chats)->toHaveCount(2)->and($chats->pluck('title')->all())->toBe(['help', 'vehicles inside'])
            ->and($chats->every(fn (SavedChat $c) => $c->login_account_id === $this->admin->id))->toBeTrue();

        // An earlier day's chat: listed under its day, opened and carried on (it stays under its day).
        $old = $chats[0];
        $old->forceFill(['chat_date' => now('Asia/Karachi')->subDays(3)->toDateString()])->save();
        $chat = Livewire::test(AssistantChat::class)->call('showTab', 'history')
            ->assertSee(now('Asia/Karachi')->subDays(3)->format('l, d M Y'))
            ->call('openChat', $old->id)->assertSet('chatId', $old->id)->assertSet('tab', 'chat')->assertSee('Type one of these commands')
            ->set('message', 'stock')->call('send');
        expect($old->fresh()->message_count)->toBe(5)->and($old->fresh()->chat_date->toDateString())->toBe(now('Asia/Karachi')->subDays(3)->toDateString());
    });

    it('never shows another person\'s chats, and a change shown on an earlier day cannot be confirmed later', function () {
        qfSignIn($this, $this->admin);
        Livewire::test(AssistantChat::class)->set('message', qfFill('party', ['Old Card', 'farmer']))->call('send')->assertSee('Add party');
        $mine = SavedChat::query()->sole();
        $mine->forceFill(['chat_date' => now('Asia/Karachi')->subDay()->toDateString()])->save();

        $other = Make::account($this->entity, 'SUPER_ADMIN');
        qfSignIn($this, $other);
        Livewire::test(AssistantChat::class)->call('showTab', 'history')->assertSee('No chats yet')->assertDontSee('Old Card')
            ->call('openChat', $mine->id)->assertSet('chatId', null);

        qfSignIn($this, $this->admin);
        $chat = Livewire::test(AssistantChat::class)->call('openChat', $mine->id)->assertSee('Answered.');
        $chat->call('confirm', 2);
        expect(Party::query()->where('name', 'Old Card')->exists())->toBeFalse();
    });

    it('greets with every feature as a button: a form to fill, a question asked at once, or a change to finish', function () {
        qfSignIn($this, $this->admin);
        $chat = Livewire::test(AssistantChat::class)->assertSee('How can I help you today?')
            ->assertSeeInOrder(['How can I help you today?', 'mnx-pill', 'Add new party', 'Vehicles inside', 'Stock', 'Approve or reject']);
        $starters = collect($chat->instance()->starters());
        expect($starters->count())->toBe(count(app(Assistant::class)->tools($this->admin)))
            ->and($starters->firstWhere('label', 'Add new party')['kind'])->toBe('quick')
            ->and($starters->firstWhere('label', 'Vehicles inside')['kind'])->toBe('send')
            ->and($starters->firstWhere('label', 'Approve or reject')['kind'])->toBe('fill');

        $chat->call('start', 'send', 'vehicles inside')->assertSee('No vehicle is inside the mill.')
            ->call('start', 'quick', 'party')->assertSet('message', QuickForms::template('party'))
            ->call('start', 'fill', 'approve 01a2b3c4')->assertSet('message', 'approve 01a2b3c4');
    });

    it('lists every feature this person has, with examples to try, quick buttons and the screens to open', function () {
        qfSignIn($this, $this->admin);
        $features = app(Assistant::class)->features($this->admin);
        $groups = array_column($features, 'group');
        $titles = collect($features)->flatMap(fn ($g) => array_column($g['items'], 'title'))->all();

        expect($groups)->toContain('Add records quickly', 'Gate and weighing', 'Business and stock', 'Any screen')
            ->and($titles)->toContain('Add new party', 'Lab test', 'Vehicles inside', 'Stock')
            ->and(count($titles))->toBe(count(app(Assistant::class)->tools($this->admin)));

        Livewire::test(AssistantChat::class)->call('showTab', 'features')
            ->assertSee('Add records quickly')->assertSee('Fill the blanks')->assertSee('Screens you can open')->assertSee('vehicles inside')
            ->call('tryExample', 'vehicles inside')->assertSet('message', 'vehicles inside')->assertSet('tab', 'chat');

        // A gate operator sees only theirs.
        $gate = Make::account($this->entity, 'GATE_OPERATOR');
        qfSignIn($this, $gate);
        $gateTitles = collect(app(Assistant::class)->features($gate))->flatMap(fn ($g) => array_column($g['items'], 'title'))->all();
        expect($gateTitles)->toContain('Vehicle arrived')->not->toContain('Pay a party');
    });

    it('does not give the blanks when something needed is missing, and links to add it', function () {
        qfSignIn($this, $this->admin);
        $start = app(Assistant::class)->quickStart($this->admin, 'sauda');

        expect($start['template'])->toBeNull()->and($start['reply']->text)->toContain('✗ No paddy product yet')
            ->and(collect($start['reply']->links)->pluck('label')->all())->toContain('Add a paddy product');
    });

    it('reads the filled message without AI: numbers in Urdu digits, words in any case', function () {
        $parsed = QuickForms::parse(qfFill('expense', ['diesel', '۴,۵۰۰', 'generator', 'Cash']));

        expect($parsed)->toBe(['key' => 'expense', 'tool' => 'record_expense',
            'input' => ['category' => 'diesel', 'amount' => '4500', 'description' => 'generator', 'paid_from' => 'cash'], 'blank' => []])
            ->and(QuickForms::parse('hello there'))->toBeNull();
    });
});

describe('what is needed before a new record', function () {
    beforeEach(function () {
        $this->entity = Make::entity('QFB');
        Make::reset();
        $this->admin = Make::account($this->entity, 'SUPER_ADMIN');
    });

    it('is shown at the top of the "new" forms', function () {
        qfSignIn($this, $this->admin);

        $this->get(PartyResource::getUrl('create'))->assertOk()->assertSee('Before you add it')->assertSee('The code is given automatically.');
        $this->get(SaudaResource::getUrl('create'))->assertOk()
            ->assertSee('Needed first')->assertSee('No paddy product yet')->assertSee('Add a paddy product');
        expect(Prerequisites::PAGES)->toHaveKeys([CreateParty::class, CreateSauda::class]);
    });
});

describe('roznamcha in Urdu', function () {
    beforeEach(function () {
        $this->f = new AccountsFixtures(Make::entity('RZU'));
        $this->today = CarbonImmutable::now('Asia/Karachi');
    });

    it('turns Urdu digits into amounts', function () {
        expect(UrduDigits::amount('۱۵,۰۰۰/-'))->toBe('15000')->and(UrduDigits::amount('Rs ٣٬٥٠٠٫٥٠'))->toBe('3500.50')
            ->and(UrduDigits::toLatin('۲۰۲۶-۱۱-۰۴'))->toBe('2026-11-04');
    });

    it('reads Urdu lines without AI: Urdu digits, جمع and بنام, the amount on either side', function () {
        $lines = RoznamchaOcr::linesFromText("جمع حاجی اسلم ۲۵,۰۰۰\n۳,۵۰۰ بنام ڈیزل\nکل میزان");

        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toMatchArray(['account' => 'حاجی اسلم', 'credit' => '25000', 'debit' => ''])
            ->and($lines[1])->toMatchArray(['account' => 'ڈیزل', 'credit' => '', 'debit' => '3500']);
    });

    it('matches an Urdu name to the mill\'s party by the name the AI recognised, and keeps the Urdu as written', function () {
        $this->f->as($this->f->owner);
        $seller = $this->f->inv->gate->seller;
        Make::system(function () {
            $group = app(ChartOfAccounts::class)->system('brokerage')->group_id;
            LedgerAccount::query()->create(['entity_id' => $this->f->entity->id, 'group_id' => $group, 'code' => 'EXP-DSL', 'name' => 'Diesel', 'kind' => 'general']);
        });
        config(['ricemill.ocr.engine' => 'auto', 'ricemill.assistant.api_key' => 'sk-ant-test-key-0000000000000000000000']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'lines' => [
                ['account' => 'حاجی صاحب', 'account_en' => 'Haji Sahib', 'match' => $seller->name, 'credit' => '۲۵,۰۰۰', 'debit' => '', 'narration' => 'دھان کی رقم', 'reference' => ''],
                ['account' => 'ڈیزل', 'account_en' => 'Diesel', 'match' => '', 'credit' => '', 'debit' => '۳۵۰۰', 'narration' => '', 'reference' => '۱۲'],
            ],
            'date' => '۲۰۲۶-۱۱-۰۴', 'text' => "جمع حاجی صاحب ۲۵,۰۰۰\nبنام ڈیزل ۳۵۰۰",
        ], JSON_UNESCAPED_UNICODE)]], 'stop_reason' => 'end_turn'])]);
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $photo = (string) ob_get_clean();

        $page = app(Roznamcha::class)->record($this->today, 'camera', $photo);

        $lines = $page->lines()->orderBy('position')->get();
        expect($lines)->toHaveCount(2)
            ->and($lines[0]->party_id)->toBe($seller->id)->and($lines[0]->credit_paise)->toBe(2_500_000)->and($lines[0]->account_text)->toBe('حاجی صاحب')
            ->and($lines[0]->narration)->toBe('دھان کی رقم')
            ->and($lines[1]->ledger_account_id)->not->toBeNull()->and($lines[1]->debit_paise)->toBe(350_000)->and($lines[1]->reference)->toBe('12')
            ->and($page->ocr_text)->toContain('حاجی صاحب');
        // The AI was given the mill's names, Urdu kept as Urdu.
        Http::assertSent(fn (Request $r) => str_contains((string) json_encode($r->data(), JSON_UNESCAPED_UNICODE), $seller->name)
            && str_contains((string) json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'بنام'));
    });

    it('keeps the whole entries of an answer cut off part way', function () {
        $this->f->as($this->f->owner);
        config(['ricemill.ocr.engine' => 'auto', 'ricemill.assistant.api_key' => 'sk-ant-test-key-0000000000000000000000']);
        $cut = '{"lines": [{"account": "بجلی", "credit": "", "debit": "۹۰۰"}, {"account": "مزدوری", "credit": "", "deb';
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $cut]], 'stop_reason' => 'max_tokens'])]);
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);

        $page = app(Roznamcha::class)->record($this->today, 'camera', (string) ob_get_clean());

        expect($page->lines()->sole()->debit_paise)->toBe(90_000)->and($page->lines()->sole()->account_text)->toBe('بجلی');
    });
});
