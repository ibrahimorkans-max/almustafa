<?php

declare(strict_types=1);

use App\Domain\Approvals\Actions\ChangeRolePermissions;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\Tools\NewFormTool;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Filament\Pages\AssistantPage;
use App\Filament\Resources\Masters\PartyResource\Pages\CreateParty;
use App\Models\Accounts\Expense;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use App\Models\Role;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\DemoMastersSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    config(['ricemill.assistant.api_key' => '']);
    $this->entity = Make::entity('AMR');
    $this->seed(DemoMastersSeeder::class);
    $this->seed(DemoActivitySeeder::class);
    Make::reset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Make::account($this->entity, 'SUPER_ADMIN');
    $this->bot = app(Assistant::class);
});

function botAs(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

describe('command mode (offline)', function () {
    it('answers questions from the books with tables', function () {
        botAs($this, $this->admin);

        $help = $this->bot->ask($this->admin, 'help');
        expect($help->table['rows'])->not->toBeEmpty()->and(collect($help->table['rows'])->pluck(0)->all())->toContain('stock', 'profit this month');

        $stock = $this->bot->ask($this->admin, 'stock');
        expect($stock->table['columns'])->toBe(['Product', 'Godown', 'kg', 'Maund'])->and($stock->table['rows'])->not->toBeEmpty();

        $inside = $this->bot->ask($this->admin, 'vehicles inside');
        expect($inside->text)->toStartWith('3 vehicle(s) inside')->and(collect($inside->table['rows'])->pluck(4)->all())->toContain('gross', 'tare');

        $profit = $this->bot->ask($this->admin, 'profit this month');
        expect(collect($profit->table['rows'])->pluck(0)->filter(fn ($l) => str_starts_with($l, 'Net '))->count())->toBe(1)
            ->and($profit->links[0]['label'])->toContain('Complete business report');

        expect($this->bot->ask($this->admin, 'open payments')->links)->not->toBeEmpty();
    });

    it('asks before changing anything, then does it as the person and audits it', function () {
        botAs($this, $this->admin);
        $before = GateEntry::query()->count();

        $reply = $this->bot->ask($this->admin, 'arrived MNA-23-9999 400 bags');
        expect($reply->pending)->not->toBeNull()->and($reply->pending['summary'])->toContain('MNA239999')->toContain('400 bags')
            ->and(GateEntry::query()->count())->toBe($before);

        $done = $this->bot->confirm($this->admin, $reply->pending);
        expect($done->text)->toContain('Arrival registered: MNA239999')
            ->and(GateEntry::query()->count())->toBe($before + 1)
            ->and(AuditLog::query()->where('action', 'assistant.action')->exists())->toBeTrue();

        $expense = $this->bot->ask($this->admin, 'expense 1,200 for meals: tea for the night shift');
        expect($expense->pending['summary'])->toContain('Rs 1,200.00')->toContain('Meals');
        $this->bot->confirm($this->admin, $expense->pending);
        expect(Expense::query()->where('amount_paise', 120_000)->sole()->status)->toBe(Expense::POSTED);
    });

    it('approves a waiting staff request by its reference', function () {
        $sup = Make::account($this->entity, 'SUPERVISOR');
        Make::system(fn () => DB::table('role_permissions')->insert([
            'role_id' => $sup->role_id, 'permission_code' => 'role.manage', 'entity_id' => $this->entity->id, 'created_at' => now(),
        ]));
        $sup->role->flushPermissionCache();
        $gateRole = Make::system(fn () => Role::query()->where('entity_id', $this->entity->id)->where('code', 'GATE_OPERATOR')->firstOrFail());
        app(AuthContextBinder::class)->bind($sup);
        $request = app(ApprovalService::class)->submit(ChangeRolePermissions::KEY, ['role_id' => $gateRole->id, 'permissions' => [...$gateRole->permissionCodes(), 'lab.test']], 'Gate needs lab');

        botAs($this, $this->admin);
        expect($this->bot->ask($this->admin, 'pending approvals')->table['rows'][0][0])->toBe(substr($request->id, 0, 8));
        $reply = $this->bot->ask($this->admin, 'approve '.substr($request->id, 0, 8));
        expect($reply->pending['summary'])->toContain('Gate needs lab');
        $this->bot->confirm($this->admin, $reply->pending);
        expect($request->fresh()->status)->toBe(ApprovalRequest::STATUS_EXECUTED);
    });

    it('offers each person only what their role allows', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');
        botAs($this, $gate);

        expect(array_keys($this->bot->tools($gate)))->toContain('vehicles_inside', 'register_vehicle_arrival')->not->toContain('business_summary', 'record_expense', 'decide_approval');
        expect($this->bot->ask($gate, 'profit this month')->table['rows'] ?? [])->not->toContain(fn ($r) => str_starts_with((string) ($r[0] ?? ''), 'Net '));
        // A change the role may not make is refused even when sent directly.
        expect($this->bot->confirm($gate, ['tool' => 'record_expense', 'input' => ['category' => 'meals', 'amount' => 5, 'description' => 'x']])->text)->toContain('not available');
    });
});

describe('AI mode (Claude)', function () {
    beforeEach(function () {
        config(['ricemill.assistant.api_key' => 'test-key', 'ricemill.assistant.model' => 'claude-sonnet-5-5']);
    });

    it('lets Claude call read-only tools as the person and answers in words', function () {
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'vehicles_inside', 'input' => (object) []]]])
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Teen gaariyan andar hain.']]]);
        botAs($this, $this->admin);

        $reply = $this->bot->ask($this->admin, 'kitni gaariyan andar hain?');

        expect($reply->text)->toBe('Teen gaariyan andar hain.')->and($reply->table['rows'])->toHaveCount(3);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'test-key') && collect($r['tools'])->pluck('name')->contains('vehicles_inside'));
        Http::assertSent(function (Request $r) {
            $last = collect($r['messages'])->last()['content'];

            return is_array($last) && ($last[0]['type'] ?? '') === 'tool_result' && str_contains($last[0]['content'], 'vehicle(s) inside');
        });
    });

    it('stops before a change and waits for Confirm', function () {
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'text', 'text' => 'I will register it.'],
            ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'register_vehicle_arrival', 'input' => ['registration_no' => 'LRA-1234']],
        ]])]);
        botAs($this, $this->admin);
        $before = GateEntry::query()->count();

        $reply = $this->bot->ask($this->admin, 'Register LRA-1234 at the gate');

        expect($reply->pending['tool'])->toBe('register_vehicle_arrival')->and($reply->text)->toBe('I will register it.')
            ->and(GateEntry::query()->count())->toBe($before);
    });

    it('falls back to commands when the AI service fails', function () {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);
        botAs($this, $this->admin);

        expect($this->bot->ask($this->admin, 'stock')->text)->toContain('AI is not reachable')->toContain('Stock in hand');
    });
});

it('chats on the Assistant screen with Confirm and Cancel', function () {
    botAs($this, $this->admin);

    Livewire::test(AssistantPage::class)->assertSee('Type a command')
        ->set('message', 'vehicles inside')->call('send')->assertSee('3 vehicle(s) inside')
        ->set('message', 'arrived MNA-23-7777')->call('send')->assertSee('Register the arrival of MNA237777')
        ->call('cancel', 4)->assertSee('Cancelled. Nothing was changed.');
    expect(GateEntry::query()->where('registration_no', 'MNA237777')->exists())->toBeFalse();

    Livewire::test(AssistantPage::class)->set('message', 'arrived MNA-23-7777')->call('send')->call('confirm', 2)->assertSee('Arrival registered');
    expect(GateEntry::query()->where('registration_no', 'MNA237777')->exists())->toBeTrue();
});

it('runs exactly the change shown on the card: the browser cannot alter it before Confirm', function () {
    botAs($this, $this->admin);

    $page = Livewire::test(AssistantPage::class)->set('message', 'arrived MNA-23-7777')->call('send')->assertSee('Register the arrival of MNA237777');
    expect(fn () => $page->set('messages.2.pending.input.registration_no', 'LES-99-1234'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    $page->call('confirm', 2)->assertSee('Arrival registered');
    expect(GateEntry::query()->where('registration_no', 'MNA237777')->exists())->toBeTrue()
        ->and(GateEntry::query()->where('registration_no', 'LES991234')->exists())->toBeFalse();
});

describe('every feature', function () {
    it('lists, searches and shows the records of any screen, with print links that work', function () {
        botAs($this, $this->admin);
        $sauda = Sauda::query()->orderBy('number')->firstOrFail();

        $list = $this->bot->ask($this->admin, 'list saudas');
        expect($list->table['rows'])->toHaveCount(2)->and(collect($list->links)->pluck('label')->implode(' '))->toContain('Print '.$sauda->number);

        $found = $this->bot->ask($this->admin, 'search parties for aslam');
        expect(collect($found->table['rows'])->pluck(0)->all())->toContain('AMR-F001');

        $shown = $this->bot->ask($this->admin, 'show sauda '.$sauda->number);
        expect(collect($shown->table['rows'])->pluck(0)->all())->toContain('Status');
        $print = collect($shown->links)->firstWhere(fn ($l) => str_starts_with($l['label'], 'Print'))['url'];
        $this->get($print)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $party = $this->bot->ask($this->admin, 'show party AMR-F001');
        $print = collect($party->links)->firstWhere(fn ($l) => str_starts_with($l['label'], 'Print'))['url'];
        $this->get($print)->assertOk();
        expect(PdfRenderer::$lastHtml)->toContain('Muhammad Aslam (demo grower)');
    });

    it('opens any "new" form with its fields, filled in from the request', function () {
        botAs($this, $this->admin);

        $fields = $this->bot->ask($this->admin, 'new party');
        expect(collect($fields->table['rows'])->pluck(0)->all())->toContain('name', 'code');

        $filled = app(NewFormTool::class)->run(['screen' => 'party', 'values' => ['name' => 'Rana Rice Traders', 'code' => 'AMR-C009', 'nonsense' => 'x']], $this->admin);
        $url = $filled->links[0]['url'];
        expect($url)->toContain('prefill');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        Livewire::withQueryParams($query)->test(CreateParty::class)
            ->assertFormSet(['name' => 'Rana Rice Traders', 'code' => 'AMR-C009']);
    });

    it('runs the gate steps, makes a sauda and a payment, each after Confirm', function () {
        botAs($this, $this->admin);
        $do = function (string $text) {
            $reply = $this->bot->ask($this->admin, $text);
            expect($reply->pending)->not->toBeNull($text);

            return $this->bot->confirm($this->admin, $reply->pending);
        };

        // Lab before weighing: the truck waiting at the lab is tested and accepted, then waits for its gross weight.
        expect($do('lab LRA7788 moisture 13.5 broken 4 grade A')->text)->toContain('Lab test recorded');
        expect($do('accept lab LRA7788')->text)->toContain('Lot accepted')->toContain('Status now: lab accepted');

        $sauda = $do('sauda 100 maund 1121 from Haji Riaz at 4500');
        expect($sauda->text)->toContain('confirmed')->and($sauda->links[0]['label'])->toBe('Print sauda contract');

        expect($do('pay Haji Riaz 5000 by cash')->text)->toContain('released');
    });
});
