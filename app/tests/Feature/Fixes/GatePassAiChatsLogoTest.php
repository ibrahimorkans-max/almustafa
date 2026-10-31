<?php

declare(strict_types=1);

use App\Domain\Assistant\ClaudeEngine;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\DocumentVerifier;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Gate\GatePasses;
use App\Domain\Messaging\Integrations;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\AiIntegrationPage;
use App\Filament\Pages\BrandingPage;
use App\Filament\Pages\ChatsPage;
use App\Filament\Resources\Gate\GatePassResource\Pages\ManageGatePasses;
use App\Models\Entity;
use App\Models\Gate\GatePass;
use App\Models\LoginAccount;
use App\Models\PartyMessage;
use App\Models\WhatsAppEvent;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    Storage::fake('local');
    $this->f = new GateFixtures(Make::entity('GPX'));
    $this->f->as($this->f->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function gpxSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

it('issues a gate pass with party, vehicle, bags and weight per bag, a serial number and a code the check page accepts', function () {
    gpxSignIn($this, $this->f->owner);
    Livewire::test(ManageGatePasses::class)
        ->callAction('create', ['purpose' => 'other', 'party_id' => $this->f->seller->id, 'party_name' => $this->f->seller->name,
            'vehicle_no' => 'mna 21 1234', 'bag_count' => '120', 'kg_per_bag' => '50', 'goods' => 'Rice'])
        ->assertHasNoActionErrors()->assertNotified()->assertDispatched('rm-print');

    $pass = GatePass::query()->sole();
    expect($pass->vehicle_no)->toBe('MNA 21 1234')->and($pass->total_kg)->toBe('6000.000')
        ->and($pass->serial)->toContain('/GP/');
    app(GatePasses::class)->pdf($pass);
    expect(PdfRenderer::$lastHtml)->toContain($pass->serial)->toContain($this->f->seller->name)->toContain('MNA-21-1234')
        ->toContain('120')->toContain('50 kg')->toContain('6,000.000 kg')->toContain(GatePasses::code($pass));

    $checked = app(DocumentVerifier::class)->verify($pass->serial, GatePasses::code($pass));
    expect($checked['type'])->toBe('Gate pass')->and($checked['facts']['Bags'])->toBe('120 × 50 kg')->and($checked['facts']['Status'])->toBe('Valid')
        ->and(app(DocumentVerifier::class)->verify($pass->serial, 'AAAA-BBBB-CCCC'))->toBeNull();

    // Cancelled: the check page says so; the pass is never changed.
    Livewire::test(ManageGatePasses::class)->callTableAction('cancel', $pass, ['reason' => 'Wrong vehicle number'])->assertNotified();
    expect(app(DocumentVerifier::class)->verify($pass->serial, GatePasses::code($pass->fresh()))['facts']['Status'])->toBe('CANCELLED: Wrong vehicle number');
});

it('refuses a gate pass without bags or with a weight of 0', function () {
    gpxSignIn($this, $this->f->owner);
    expect(fn () => app(GatePasses::class)->issue(['party_name' => 'X', 'vehicle_no' => 'LES 1', 'bag_count' => 0, 'kg_per_bag' => '50']))
        ->toThrow(BusinessRuleViolation::class, 'bags')
        ->and(fn () => app(GatePasses::class)->issue(['party_name' => 'X', 'vehicle_no' => 'LES 1', 'bag_count' => 5, 'kg_per_bag' => '0']))
        ->toThrow(BusinessRuleViolation::class, 'weight per bag');
});

it('prints selling documents with the logo for selling and everything else with the mill\'s logo', function () {
    $sa = Make::account($this->f->entity, 'SUPER_ADMIN', ['mfa' => true]);
    gpxSignIn($this, $sa);
    Livewire::test(BrandingPage::class)
        ->fillForm(['legal_name' => 'GPX Mills', 'logo' => [UploadedFile::fake()->image('logo.png', 600, 200)],
            'sales_logo' => [UploadedFile::fake()->image('sales.png', 600, 200)]])
        ->call('save')->assertHasNoFormErrors();
    $mill = Make::system(fn () => Entity::query()->findOrFail($this->f->entity->id));
    expect($mill->sales_logo_path)->toContain('sales_logo-');

    expect(PdfRenderer::brand($mill)['logo'])->toBe(Storage::disk('local')->path($mill->logo_path))
        ->and(PdfRenderer::brand($mill, 'sales')['logo'])->toBe(Storage::disk('local')->path($mill->sales_logo_path));

    $sale = app(GatePasses::class)->issue(['party_name' => 'Buyer', 'vehicle_no' => 'LES 1', 'bag_count' => 10, 'kg_per_bag' => '25', 'purpose' => 'sale']);
    app(GatePasses::class)->pdf($sale);
    expect(PdfRenderer::$lastHtml)->toContain(basename($mill->sales_logo_path));
    $other = app(GatePasses::class)->issue(['party_name' => 'Buyer', 'vehicle_no' => 'LES 1', 'bag_count' => 10, 'kg_per_bag' => '25', 'purpose' => 'other']);
    app(GatePasses::class)->pdf($other);
    expect(PdfRenderer::$lastHtml)->toContain(basename($mill->logo_path))->not->toContain(basename($mill->sales_logo_path));
});

it('saves an AI key only after Anthropic accepts it, never shows it, and the Assistant uses it', function () {
    gpxSignIn($this, $this->f->owner);
    $key = 'sk-ant-api03-'.str_repeat('x', 40).'ABCD';
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'invalid x-api-key']], 401)
        ->push(['content' => [['type' => 'text', 'text' => 'OK']]], 200)]);

    Livewire::test(AiIntegrationPage::class)->assertSee('No key')
        ->callAction('saveKey', ['api_key' => $key, 'model' => 'claude-sonnet-5-5'])->assertNotified('The provider refused the key (wrong, or deleted). Copy it again from console.anthropic.com → API keys (starts with sk-ant-).');
    expect(Integrations::aiSaved($this->f->entity->id))->toBeNull();

    Livewire::test(AiIntegrationPage::class)
        ->callAction('saveKey', ['api_key' => $key, 'model' => 'claude-haiku-5-5'])->assertNotified('Saved and checked: the AI assistant uses this key now.')
        ->assertSee('Connected: the mill\'s own key', false)->assertSee('…ABCD')->assertDontSee($key);
    expect(ClaudeEngine::settings())->toMatchArray(['api_key' => $key, 'model' => 'claude-haiku-5-5', 'source' => 'mill'])
        ->and(app(ClaudeEngine::class)->configured())->toBeTrue();
    Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', $key) && $r['model'] === 'claude-haiku-5-5');

    expect(fn () => Integrations::saveAi($this->f->entity->id, ['api_key' => 'not-a-key', 'model' => 'claude-sonnet-5-5']))
        ->toThrow(BusinessRuleViolation::class, 'sk-ant-');
});

it('shows every message to a party and its replies as one conversation', function () {
    gpxSignIn($this, $this->f->owner);
    $seller = $this->f->seller;
    Make::system(function () use ($seller): void {
        $m = new PartyMessage(['party_id' => $seller->id, 'event' => 'weighed', 'channel' => 'whatsapp', 'recipient' => '+923001234567',
            'body' => 'Final weights: net 15,250 kg', 'status' => PartyMessage::SENT, 'sent_at' => now(), 'delivery_status' => 'read', 'delivery_at' => now()]);
        $m->entity_id = $seller->entity_id;
        $m->save();
        WhatsAppEvent::query()->create(['entity_id' => $seller->entity_id, 'scope' => 'platform', 'kind' => 'message', 'wa_message_id' => 'wamid.R1',
            'status' => 'received', 'phone' => '+923001234567', 'party_id' => $seller->id, 'type' => 'text', 'body' => 'Shukriya, payment kab?',
            'occurred_at' => now()->addMinute(), 'created_at' => now()]);
    });

    Livewire::test(ChatsPage::class)
        ->assertSee($seller->name)->assertSee('Conversations (1)')
        ->call('open', 'p:'.$seller->id)
        ->assertSee('Final weights: net 15,250 kg')->assertSee('✓✓ Read')->assertSee('Shukriya, payment kab?')->assertSee('received on WhatsApp')
        ->set('search', 'nobody-like-this')->assertSee('Nothing matches.');
});
