<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\Roznamcha;
use App\Domain\Accounts\RoznamchaBudgets;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\RoznamchaResource\Pages\ListRoznamchaPages;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\RoznamchaPage;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    Storage::fake('local');
    $this->f = new AccountsFixtures(Make::entity('RZB'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->today = CarbonImmutable::now('Asia/Karachi');
});

function rzbSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

/** A phone photo of the page (a real JPEG). */
function rzbPhoto(): string
{
    $img = imagecreatetruecolor(400, 300);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    ob_start();
    imagejpeg($img, null, 85);

    return (string) ob_get_clean();
}

it('reads the complete phone photo with the AI and saves every entry, matched to its party or account', function () {
    $this->f->as($this->f->owner);
    $seller = $this->f->inv->gate->seller;
    $diesel = Make::system(function () {
        $group = app(ChartOfAccounts::class)->system('brokerage')->group_id;

        return LedgerAccount::query()->create(['entity_id' => $this->f->entity->id, 'group_id' => $group, 'code' => 'EXP-DSL', 'name' => 'Diesel', 'kind' => 'general']);
    });
    config(['ricemill.ocr.engine' => 'auto', 'ricemill.assistant.api_key' => 'sk-ant-test-key-0000000000000000000000']);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
        'date' => $this->today->toDateString(), 'text' => 'Jama '.$seller->name." 25,000\nBanam Diesel 3,500\nBanam Mazdoori 1,200",
        'lines' => [
            ['account' => mb_strtoupper($seller->name), 'credit' => '25,000', 'debit' => '', 'narration' => 'advance back', 'reference' => ''],
            ['account' => 'diesel', 'credit' => '', 'debit' => '3500', 'narration' => 'pump', 'reference' => 'B-17'],
            ['account' => 'Mazdoori', 'credit' => '', 'debit' => '1,200', 'narration' => '', 'reference' => ''],
        ],
    ])]]])]);

    $page = app(Roznamcha::class)->record($this->today, 'camera', rzbPhoto());

    expect($page->ocr_engine)->toBe('claude')
        ->and($page->lines()->count())->toBe(3);
    $lines = $page->lines()->orderBy('position')->get();
    expect($lines[0]->party_id)->toBe($seller->id)->and($lines[0]->credit_paise)->toBe(2_500_000)
        ->and($lines[1]->ledger_account_id)->toBe($diesel->id)->and($lines[1]->debit_paise)->toBe(350_000)->and($lines[1]->reference)->toBe('B-17')
        ->and($lines[2]->party_id)->toBeNull()->and($lines[2]->ledger_account_id)->toBeNull()->and($lines[2]->account_text)->toBe('Mazdoori');
    Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data()), 'COMPLETE page') && $r['max_tokens'] === 8000
        && $r['messages'][0]['content'][0]['type'] === 'image');
});

it('posts a page over the day\'s budget only with the reason, and shows what is left', function () {
    $this->f->as($this->f->owner);
    $budgets = app(RoznamchaBudgets::class);
    $budgets->setUsual(5_000_000);   // Rs 50,000
    $svc = app(Roznamcha::class);
    $fuel = app(ChartOfAccounts::class)->system('brokerage');

    $page = $svc->record($this->today, 'manual');
    $svc->saveLines($page, [['ledger_account_id' => $fuel->id, 'debit' => '60,000']]);
    expect($budgets->day($this->today))->toMatchArray(['budget' => 5_000_000, 'paid' => 6_000_000, 'left' => -1_000_000, 'over' => true]);

    $this->f->as($this->f->accountant);
    $svc->verify($page);
    expect(fn () => $svc->post($page))->toThrow(BusinessRuleViolation::class, 'over its budget of Rs 50,000 by Rs 10,000');
    $posted = $svc->post($page, 'Paddy payments due today');
    expect($posted->status)->toBe('posted')->and($posted->fresh()->over_budget_reason)->toBe('Paddy payments due today');

    // A day of its own: a higher budget for tomorrow; back to the usual when cleared.
    $this->f->as($this->f->owner);
    $tomorrow = $this->today->addDay();
    $budgets->setDay($tomorrow, 10_000_000, 'Payments day');
    expect($budgets->budgetFor($tomorrow))->toBe(10_000_000);
    $budgets->setDay($tomorrow, null);
    expect($budgets->budgetFor($tomorrow))->toBe(5_000_000);
});

it('lets the Owner set the daily budget on the roznamcha list, which shows today\'s budget', function () {
    rzbSignIn($this, $this->f->owner);
    Livewire::test(ListRoznamchaPages::class)
        ->assertSee('no daily budget set')
        ->callAction('budget', ['what' => 'usual', 'usual' => '50000'])->assertNotified('Budget saved.');
    expect(app(RoznamchaBudgets::class)->usual($this->f->entity->id))->toBe(5_000_000);
    Livewire::test(ListRoznamchaPages::class)->assertSee('Rs 50,000.00')->assertSee('left');

    // Not for someone who does not approve payments or manage settings.
    rzbSignIn($this, Make::account($this->f->entity, 'GATE_OPERATOR'));
    expect(RoznamchaBudgets::canSet())->toBeFalse()
        ->and(fn () => app(RoznamchaBudgets::class)->setUsual(1))->toThrow(BusinessRuleViolation::class);
    expect(RoznamchaPage::query()->count())->toBe(0);
});
