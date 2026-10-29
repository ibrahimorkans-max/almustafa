<?php

declare(strict_types=1);

use App\Domain\Accounts\NetworkScanner;
use App\Domain\Accounts\RoznamchaCapture;
use App\Domain\Messaging\Gateways\FirebasePush;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Messaging\Integrations;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Pages\Platform\PlatformIntegrationsPage;
use App\Filament\Resources\Accounts\RoznamchaResource\Pages\CreateRoznamchaPage;
use App\Models\Accounts\RoznamchaPage;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

/*
 * WhatsApp and Firebase connected from inside the system, and the roznamcha inputs: a phone's camera by
 * QR code / link, a network scanner, and entries typed on the form.
 */
const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('INT'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f->as($this->f->owner);
    $this->entityId = $this->f->entity->id;
    config(['ricemill.messaging.whatsapp_cloud.phone_number_id' => '', 'ricemill.messaging.whatsapp_cloud.access_token' => '', 'ricemill.messaging.firebase.credentials' => '']);
    Storage::disk('local')->deleteDirectory('integrations');
});

afterEach(fn () => Storage::disk('local')->deleteDirectory('integrations'));

function rzInSignIn(object $t, LoginAccount $account): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

it('connects a mill\'s own WhatsApp number after checking it with Meta, keeps the token encrypted, and sends from it', function () {
    Http::fake([
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        'graph.facebook.com/*/111222333444*' => Http::response(['display_phone_number' => '+92 300 1234567', 'verified_name' => 'Al Mustafa Rice Mills']),
    ]);
    expect(WhatsAppCloud::configured($this->entityId))->toBeFalse();

    $saved = Integrations::saveWhatsapp($this->entityId, ['phone_number_id' => '111222333444', 'access_token' => 'EAAG-secret-token', 'template' => 'mill_update', 'language' => 'en']);
    expect($saved['business_number'])->toBe('Al Mustafa Rice Mills · +92 300 1234567')
        ->and(WhatsAppCloud::configured($this->entityId))->toBeTrue()
        ->and(Integrations::whatsapp($this->entityId)['source'])->toBe('mill')
        ->and(Integrations::whatsappSaved($this->entityId)['access_token'])->toBe('(saved)')
        ->and(Storage::disk('local')->get('integrations/whatsapp-'.$this->entityId.'.json'))->not->toContain('EAAG-secret-token');

    expect((new WhatsAppCloud($this->entityId))->send('923001234567', 'Hello')['ok'])->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/111222333444/messages') && $r->hasHeader('Authorization', 'Bearer EAAG-secret-token'));

    // Leaving the token empty keeps it; another mill uses the product's number, not this one.
    Integrations::saveWhatsapp($this->entityId, ['phone_number_id' => '111222333444', 'access_token' => '', 'template' => 'mill_update_2']);
    expect(Integrations::whatsapp($this->entityId)['access_token'])->toBe('EAAG-secret-token')
        ->and(Integrations::whatsapp('01a00000-0000-7000-8000-000000000000')['source'])->toBe('server');
});

it('refuses WhatsApp settings Meta does not accept, and saves nothing', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 401)]);

    expect(fn () => Integrations::saveWhatsapp($this->entityId, ['phone_number_id' => '111222333444', 'access_token' => 'wrong']))
        ->toThrow(BusinessRuleViolation::class, 'Invalid OAuth access token');
    expect(Integrations::whatsappSaved($this->entityId))->toBeNull();
});

it('connects the product\'s Firebase project with a service account key that Google accepts', function () {
    // A throwaway key made now (Windows PHP needs an OpenSSL config file to make one).
    $cnf = tempnam(sys_get_temp_dir(), 'ssl');
    file_put_contents($cnf, '[ req ]
distinguished_name = dn
[ dn ]
');
    $pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
    openssl_pkey_export($pk, $pem, null, ['config' => $cnf]);
    @unlink($cnf);
    $key = json_encode(['type' => 'service_account', 'project_id' => 'millnexa-test', 'client_email' => 'push@millnexa-test.iam.gserviceaccount.com',
        'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600])]);

    expect(fn () => Integrations::saveFirebase('{"type":"user"}', [], ''))->toThrow(BusinessRuleViolation::class, 'not a Firebase service account');
    Integrations::saveFirebase($key, ['apiKey' => 'AIza-test', 'messagingSenderId' => '123', 'projectId' => 'millnexa-test', 'appId' => '1:123:web:abc'], 'BVapid');

    expect(FirebasePush::configured())->toBeTrue()->and(FirebasePush::webReady())->toBeTrue()
        ->and(app(FirebasePush::class)->check())->toBeNull()
        ->and(Storage::disk('local')->get('integrations/firebase.json'))->not->toContain('PRIVATE KEY');
});

it('connects WhatsApp from Administration → Integrations, and shows the product owner\'s page', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+92 300 7654321'])]);
    rzInSignIn($this, $this->f->owner);
    Livewire::test(IntegrationsPage::class)->assertOk()->assertSee('Not connected')
        ->callAction('connectWhatsapp', ['phone_number_id' => '555666777888', 'access_token' => 'EAAG-mill', 'template' => 'mill_update', 'language' => 'en'])
        ->assertNotified('WhatsApp connected: +92 300 7654321');
    expect(Integrations::whatsapp($this->entityId)['phone_number_id'])->toBe('555666777888');

    rzInSignIn($this, Make::platformAdmin($this->f->entity));
    Livewire::test(PlatformIntegrationsPage::class)->assertOk()->assertSee('Firebase (notifications on party phones and browsers)');
});

it('takes a roznamcha photo with a phone\'s camera through a QR code link, and keeps it with the page', function () {
    rzInSignIn($this, $this->f->owner);
    $page = Livewire::test(CreateRoznamchaPage::class)->fillForm(['source' => 'camera'])->call('startCapture')->assertSee('Waiting for the photo');
    $token = $page->get('data.capture_token');
    $url = RoznamchaCapture::url($token);

    // The phone: the signed link only (an unsigned or altered one is refused), one photo.
    $this->get(route('roznamcha.capture', ['token' => $token]))->assertForbidden();
    $this->get($url)->assertOk()->assertSee('Open the camera');
    $this->post($url, ['photo' => UploadedFile::fake()->createWithContent('page.png', base64_decode(PNG_1PX))])->assertOk()->assertSee('Photo sent');
    $this->post($url, ['photo' => UploadedFile::fake()->createWithContent('page.png', base64_decode(PNG_1PX))])->assertSee('already sent');

    // Back on the computer (each of its requests is in the mill).
    $this->f->as($this->f->owner);
    $page->call('checkCapture')->assertSet('data.capture_file_id', fn ($v) => filled($v))->assertNotified('Photo received from the phone.')
        ->fillForm(['entry_date' => now('Asia/Karachi')->toDateString()])->call('create')->assertHasNoFormErrors();
    expect(RoznamchaPage::query()->sole()->stored_file_id)->toBe($page->get('data.capture_file_id'));
});

it('reads a page from the mill\'s network scanner, and only from the private network', function () {
    expect(NetworkScanner::validate('http://192.168.1.50'))->toBe('http://192.168.1.50/eSCL');
    foreach (['http://8.8.8.8', 'http://127.0.0.1', 'http://169.254.169.254', 'file:///etc/passwd'] as $bad) {
        expect(fn () => NetworkScanner::validate($bad))->toThrow(BusinessRuleViolation::class);
    }

    config(['ricemill.mill_server' => true]);
    app(SettingsService::class)->put(SettingsService::SCANNER_URL, 'http://192.168.1.50/eSCL', $this->entityId);
    Http::fake([
        '192.168.1.50/eSCL/ScanJobs' => Http::response('', 201, ['Location' => 'http://192.168.1.50/eSCL/ScanJobs/job-1']),
        '192.168.1.50/eSCL/ScanJobs/job-1/NextDocument' => Http::response(base64_decode(PNG_1PX), 200, ['Content-Type' => 'image/png']),
    ]);
    rzInSignIn($this, $this->f->owner);
    Livewire::test(CreateRoznamchaPage::class)->fillForm(['source' => 'scanner'])->assertSee('Scan now')
        ->call('scanNow')->assertNotified('Page scanned.')
        ->fillForm(['entry_date' => now('Asia/Karachi')->toDateString()])->call('create')->assertHasNoFormErrors();
    expect(RoznamchaPage::query()->sole()->stored_file_id)->not->toBeNull();
});

it('takes typed entries straight on the form', function () {
    rzInSignIn($this, $this->f->owner);
    $party = $this->f->inv->gate->seller;
    Livewire::test(CreateRoznamchaPage::class)
        ->fillForm(['entry_date' => now('Asia/Karachi')->toDateString(), 'source' => 'manual',
            'lines' => [['party_id' => $party->id, 'credit' => '5000', 'narration' => 'Advance back']]])
        ->call('create')->assertHasNoFormErrors();
    $page = RoznamchaPage::query()->sole();
    expect($page->stored_file_id)->toBeNull()->and($page->lines()->sole()->credit_paise)->toBe(500_000);
});
