<?php

declare(strict_types=1);

use App\Domain\Assistant\Assistant;
use App\Domain\Auth\AuthContextBinder;
use App\Support\FeatureDescriptions;
use App\Support\FeatureHowTo;
use Filament\Facades\Filament;
use Tests\Support\Make;

beforeEach(function () {
    config(['ricemill.assistant.api_key' => '']);
    $this->entity = Make::entity('FGD');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->owner = Make::account($this->entity, 'OWNER');
    $this->actingAs($this->owner, 'web')->withSession(Make::webSession($this->owner));
    app(AuthContextBinder::class)->bind($this->owner);
});

it('has a purpose for every screen it explains, and steps for each', function () {
    foreach (FeatureHowTo::ALL as $screen => $entry) {
        expect(FeatureDescriptions::ALL)->toHaveKey($screen)
            ->and($entry['how'])->not->toBe('')
            ->and($entry['steps'])->not->toBeEmpty();
    }
});

it('explains every screen that has a purpose, all of it in Urdu too', function () {
    expect(array_keys(array_diff_key(FeatureDescriptions::ALL, FeatureHowTo::ALL)))->toBe([]);

    $ur = json_decode((string) file_get_contents(lang_path('ur.json')), true);
    $missing = [];
    foreach (FeatureHowTo::ALL as $entry) {
        foreach ([$entry['how'], ...$entry['steps']] as $text) {
            if (! isset($ur[$text])) {
                $missing[] = $text;
            }
        }
    }
    expect($missing)->toBe([]);
});

it('answers how-to questions about the other screens too', function () {
    $bot = app(Assistant::class);
    expect($bot->ask($this->owner, 'how do I lock a month')->text)->toStartWith('Period locks:')
        ->and($bot->ask($this->owner, 'how to import old records from excel')->text)->toStartWith('Old records (from paper):')
        ->and($bot->ask($this->owner, 'how do I approve a new device')->text)->toStartWith('Devices:');
});

it('shows how to use each screen on the Features guide', function () {
    $this->get('/admin/features-guide')->assertOk()
        ->assertSee('How to use')
        ->assertSee('Quick bills')
        ->assertSee('Press &quot;New quick bill&quot; and choose what it is for', false)
        ->assertSee('Salary vouchers')
        ->assertSee('Stock report');
});

it('answers "how do I …" in the chat with the purpose, the steps and a link', function () {
    $bot = app(Assistant::class);

    $bill = $bot->ask($this->owner, 'how do I make a quick bill?');
    expect($bill->text)->toStartWith('Quick bills:')
        ->toContain('How it works:')->toContain('1. Press "New quick bill"')
        ->and($bill->links[0]['label'])->toBe('Open Quick bills');

    expect($bot->ask($this->owner, 'salary voucher kaise banate hain')->text)->toStartWith('Salary vouchers:')
        ->and($bot->ask($this->owner, 'how to move stock godown to godown')->text)->toStartWith('Requisitions:')
        ->and($bot->ask($this->owner, 'explain party balances')->text)->toStartWith('Party balances:')
        ->and($bot->ask($this->owner, 'what is the stock report for?')->text)->toStartWith('Stock report:');

    // Still the stock figures, not the guide.
    expect($bot->ask($this->owner, 'stock')->text)->not->toContain('How it works');

    $features = collect($bot->features($this->owner))->firstWhere('group', 'Learn the system');
    expect($features['items'][0]['title'])->toBe('How do I …?');
});

it('explains only the screens the person may open', function () {
    $gate = Make::account($this->entity, 'GATE_OPERATOR');
    app('auth')->forgetGuards();
    $this->actingAs($gate, 'web')->withSession(Make::webSession($gate));
    app(AuthContextBinder::class)->bind($gate);

    expect(app(Assistant::class)->ask($gate, 'how do I make a salary voucher')->text)->not->toStartWith('Salary vouchers:');
});
