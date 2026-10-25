<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Messaging\PartyAccounts;
use App\Models\LoginAccount;
use App\Models\PartyMessage;
use App\Models\PartyPushToken;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('GWY'));
    $this->seller = $this->f->inv->gate->seller;
    $this->f->as($this->f->owner);
    $this->seller->forceFill(['phone' => '+923001234567'])->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Cache::flush();
});

afterEach(function () {
    // The service account keys made for the Firebase test.
    array_map('unlink', glob(storage_path('app/private/test-firebase-*.json')) ?: []);
});

function gwySignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

/** A Firebase service account key with a real RSA key, so the token request is really signed. */
function gwyFirebaseKey(): string
{
    // Windows PHP builds need OpenSSL's config file to make a key; it ships next to php.exe.
    $cnf = getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($cnf) ? ['config' => $cnf] : []);
    $key = openssl_pkey_new($options);
    openssl_pkey_export($key, $pem, null, is_file($cnf) ? ['config' => $cnf] : []);
    $path = storage_path('app/private/test-firebase-'.uniqid().'.json');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, json_encode(['type' => 'service_account', 'project_id' => 'mill-test', 'client_email' => 'push@mill-test.iam.gserviceaccount.com',
        'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));

    return $path;
}

it('sends party messages by the WhatsApp Business Cloud API as the approved template', function () {
    config(['ricemill.messaging.whatsapp_cloud.phone_number_id' => '1234567890', 'ricemill.messaging.whatsapp_cloud.access_token' => 'meta-token']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
    $this->entry = $this->f->receiveLot();
    $this->f->as($this->f->owner);

    $sent = PartyMessage::query()->where('event', 'weighed')->where('channel', 'whatsapp')->sole();
    expect($sent->status)->toBe(PartyMessage::SENT);
    Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v21.0/1234567890/messages'
        && $r->hasHeader('Authorization', 'Bearer meta-token')
        && $r['to'] === '923001234567' && $r['type'] === 'template' && $r['template']['name'] === 'mill_update'
        && $r['template']['components'][0]['parameters'][0]['text'] === '*Final weights of your vehicle* · Vehicle: *MNA-21-1234* · Slip: '.$this->entry->number
            .' · Item: Paddy 1121 · Loaded: 25,480 kg (637.00 maund) · Empty: 10,230 kg (255.75 maund) · Net weight: *15,250 kg (381.25 maund)* · Payable weight: *14,781 kg (369.53 maund)*');
});

it('keeps the WhatsApp error when Meta refuses, for sending by hand', function () {
    config(['ricemill.messaging.whatsapp_cloud.phone_number_id' => '1234567890', 'ricemill.messaging.whatsapp_cloud.access_token' => 'meta-token']);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template name does not exist in the translation']], 404)]);
    $this->f->receiveLot();
    $this->f->as($this->f->owner);

    $failed = PartyMessage::query()->where('event', 'weighed')->sole();
    expect($failed->status)->toBe(PartyMessage::FAILED)->and($failed->error)->toContain('Template name does not exist');
});

it('sends a Firebase notification to each device the party turned notifications on for', function () {
    config(['ricemill.messaging.firebase.credentials' => gwyFirebaseKey()]);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-access', 'expires_in' => 3600]),
        // The old browser's token is no longer known to Firebase; the phone takes every message.
        'fcm.googleapis.com/*' => fn ($request) => $request['message']['token'] === str_repeat('b', 40)
            ? Http::response(['error' => ['status' => 'NOT_FOUND']], 404)
            : Http::response(['name' => 'projects/mill-test/messages/1']),
    ]);

    // The party signs in and turns notifications on for two devices.
    $login = app(PartyAccounts::class)->grant($this->seller)['account'];
    $login->forceFill(['must_change_password' => false])->save();
    gwySignIn($this, $login);
    $this->postJson(route('filament.admin.push-token'), ['token' => str_repeat('a', 40), 'device' => 'Phone'])->assertOk();
    $this->postJson(route('filament.admin.push-token'), ['token' => str_repeat('b', 40), 'device' => 'Old browser'])->assertOk();
    $this->postJson(route('filament.admin.push-token'), ['token' => str_repeat('a', 40)])->assertOk();   // same device again
    expect(PartyPushToken::query()->count())->toBe(2);

    $this->f->receiveLot();
    $this->f->as($this->f->owner);
    $push = PartyMessage::query()->where('event', 'weighed')->where('channel', 'push')->sole();
    expect($push->status)->toBe(PartyMessage::SENT)
        ->and(PartyPushToken::query()->count())->toBe(1);   // the device Firebase no longer knows is removed
    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com') && count(explode('.', (string) $r['assertion'])) === 3);
    Http::assertSent(fn ($r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/mill-test/messages:send'
        && $r->hasHeader('Authorization', 'Bearer google-access') && str_contains($r['message']['notification']['body'], 'Net weight: 15,250 kg (381.25 maund)')
        && ! str_contains($r['message']['notification']['body'], '*') && str_starts_with($r['message']['notification']['title'], 'Al Mustafa Rice Mills'));
});

it('lets only a party register a device, and serves the notification worker', function () {
    gwySignIn($this, $this->f->owner);
    $this->postJson(route('filament.admin.push-token'), ['token' => str_repeat('a', 40)])->assertForbidden();

    config(['ricemill.messaging.firebase.web.projectId' => 'mill-test', 'ricemill.messaging.firebase.web.apiKey' => 'web-key']);
    $this->get('/firebase-messaging-sw.js')->assertOk()->assertHeader('Content-Type', 'application/javascript')
        ->assertSee('firebase-messaging-compat.js', false)->assertSee('"projectId":"mill-test"', false);
});
