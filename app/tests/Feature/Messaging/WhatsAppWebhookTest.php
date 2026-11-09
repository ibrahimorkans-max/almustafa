<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Messaging\Integrations;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\WhatsAppWebhookPage;
use App\Models\LoginAccount;
use App\Models\PartyMessage;
use App\Models\WhatsAppEvent;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    Storage::fake('local');   // the encrypted integration files
    $this->f = new AccountsFixtures(Make::entity('WHK'));
    $this->seller = $this->f->inv->gate->seller;
    $this->f->as($this->f->owner);
    $this->seller->forceFill(['phone' => '0300-1234567'])->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->secret = str_repeat('ab12', 8);
    Integrations::saveWhatsappWebhookSecret('platform', $this->secret);
    $this->token = Integrations::whatsappWebhook('platform')['verify_token'];
});

function whkSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

/** Posts a payload to the webhook as Meta does: the raw body signed with the app secret. */
function whkPost(object $t, array $value, ?string $secret = null, string $scope = 'platform'): TestResponse
{
    $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => $value]]]]]);

    return $t->call('POST', '/api/webhooks/whatsapp/'.$scope, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? $t->secret),
    ], $body);
}

it('answers Meta\'s check with the challenge only for the right verify token', function () {
    $url = '/api/webhooks/whatsapp/platform?hub.mode=subscribe&hub.challenge=1158201444&hub.verify_token=';

    $this->get($url.$this->token)->assertOk()->assertSeeText('1158201444');
    $this->get($url.'wrong')->assertForbidden();
    $this->get('/api/webhooks/whatsapp/'.Str::uuid().'?hub.mode=subscribe&hub.challenge=1&hub.verify_token='.$this->token)->assertForbidden();
    expect(Integrations::whatsappWebhook('platform')['url'])->toEndWith('/api/webhooks/whatsapp/platform');
});

it('records delivery and read receipts on the message sent, once each, and refuses unsigned calls', function () {
    config(['ricemill.messaging.whatsapp_cloud.phone_number_id' => '1234567890', 'ricemill.messaging.whatsapp_cloud.access_token' => 'meta-token']);
    Make::whatsappSender($this->f->entity);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);
    $this->f->receiveLot();
    $this->f->as($this->f->owner);
    $sent = PartyMessage::query()->where('event', 'weighed')->where('channel', 'whatsapp')->sole();
    expect($sent->provider_message_id)->toBe('wamid.ABC');

    $status = fn (string $s, int $at) => ['statuses' => [['id' => 'wamid.ABC', 'status' => $s, 'timestamp' => (string) $at, 'recipient_id' => '923001234567']]];
    whkPost($this, $status('delivered', 1793000000))->assertOk();
    whkPost($this, $status('read', 1793000060))->assertOk();
    whkPost($this, $status('read', 1793000060))->assertOk();          // Meta sends it again
    whkPost($this, $status('delivered', 1793000000))->assertOk();     // late: never goes back

    // (read as the system: the webhook call left no mill chosen)
    expect(Make::system(fn () => $sent->fresh()->delivery_status))->toBe('read')
        ->and(Make::system(fn () => WhatsAppEvent::query()->where('wa_message_id', 'wamid.ABC')->count()))->toBe(2)
        ->and(Make::system(fn () => WhatsAppEvent::progressOf('wamid.ABC')))->toBe('read')
        ->and(Make::system(fn () => WhatsAppEvent::query()->where('wa_message_id', 'wamid.ABC')->first()->entity_id))->toBe($this->f->entity->id);

    // Not signed with the app secret: refused, nothing kept.
    whkPost($this, $status('failed', 1793000100), secret: str_repeat('0', 32))->assertForbidden();
    expect(Make::system(fn () => $sent->fresh()->delivery_status))->toBe('read');
});

it('keeps a message a party sent, with the party found by its mobile number', function () {
    whkPost($this, ['messages' => [['from' => '923001234567', 'id' => 'wamid.IN1', 'timestamp' => '1793000000', 'type' => 'text', 'text' => ['body' => 'Rate kya hai?']]]])->assertOk();

    $in = Make::system(fn () => WhatsAppEvent::query()->where('kind', 'message')->sole());
    expect($in->party_id)->toBe($this->seller->id)->and($in->entity_id)->toBe($this->f->entity->id)
        ->and($in->body)->toBe('Rate kya hai?')->and($in->phone)->toBe('+923001234567');
});

it('refuses everything until the app secret is saved, and checks the secret', function () {
    $entity = $this->f->entity->id;
    $token = Integrations::whatsappWebhook($entity)['verify_token'];
    expect($token)->not->toBe($this->token);   // each number has its own
    whkPost($this, ['messages' => []], scope: $entity)->assertForbidden();

    expect(fn () => Integrations::saveWhatsappWebhookSecret($entity, 'not-a-secret'))->toThrow(BusinessRuleViolation::class, 'App secret');
});

it('shows only the SaaS owner the one webhook — its callback URL, verify token and what came back', function () {
    whkPost($this, ['messages' => [['from' => '923001234567', 'id' => 'wamid.IN2', 'timestamp' => '1793000000', 'type' => 'text', 'text' => ['body' => 'Shukriya']]]])->assertOk();
    whkSignIn($this, $this->f->owner);
    expect(WhatsAppWebhookPage::canAccess())->toBeFalse();   // no mill registers its own webhook

    whkSignIn($this, Make::platformAdmin($this->f->entity));
    $hook = Integrations::whatsappWebhook('platform');
    Livewire::test(WhatsAppWebhookPage::class)
        ->assertSee($hook['url'])->assertSee($hook['verify_token'])
        ->assertSee('Shukriya')->assertSee('Message received')
        ->callAction('renewToken')->assertNotified();
    expect(Integrations::whatsappWebhook('platform')['verify_token'])->not->toBe($hook['verify_token']);
});
