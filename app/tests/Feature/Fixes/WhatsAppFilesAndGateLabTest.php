<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GatePasses;
use App\Domain\Gate\GateService;
use App\Domain\Messaging\WhatsAppFiles;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ListGateEntries;
use App\Filament\Resources\Gate\GatePassResource\Pages\ManageGatePasses;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
use App\Models\PartyMessage;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    Storage::fake('local');
    $this->f = new GateFixtures(Make::entity('WAF'));
    $this->f->as($this->f->owner);
    $this->f->seller->forceFill(['phone' => '0300-1234567'])->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config(['ricemill.messaging.whatsapp_cloud.phone_number_id' => '1234567890', 'ricemill.messaging.whatsapp_cloud.access_token' => 'meta-token']);
    Make::whatsappSender($this->f->entity);
});

function wafSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

function wafFake(): void
{
    Http::fake([
        'graph.facebook.com/v21.0/1234567890/media' => Http::response(['id' => 'MEDIA-1']),
        'graph.facebook.com/v21.0/1234567890/messages' => Http::response(['messages' => [['id' => 'wamid.FILE1']]]),
    ]);
}

it('lets the gate operator record a vehicle\'s lab test, leaving the decision to the Lab Supervisor', function () {
    $sauda = $this->f->confirmedSauda('100000');
    $operator = Make::account($this->f->entity, 'GATE_OPERATOR');
    $this->f->as($operator);
    $lot = app(GateService::class)->arrive(['registration_no' => 'MNA 21 1234', 'sauda_id' => $sauda->id, 'bag_count' => 400]);
    wafSignIn($this, $operator);

    Livewire::test(ListGateEntries::class)->assertActionVisible('quickLabTest')
        ->mountAction('quickLabTest')
        ->setActionData(['entry' => $lot->id, 'r_moisture' => '14.5'])
        ->assertDontSee('Accept the lot: it can be weighed')
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified();

    expect(LabTest::query()->where('gate_entry_id', $lot->id)->sole()->results)->toBe(['moisture' => 1450])
        ->and($lot->fresh()->status)->toBe('arrived');
});

it('sends a gate pass as a file on WhatsApp with the mill\'s own short message, and keeps it in Chats', function () {
    wafFake();
    wafSignIn($this, $this->f->owner);
    app(WhatsAppFiles::class)->saveTemplate($this->f->entity->id, 'Assalam o alaikum {party}, your {document} {number} is attached.');
    $pass = app(GatePasses::class)->issue(['party_id' => $this->f->seller->id, 'party_name' => $this->f->seller->name, 'vehicle_no' => 'LES 1',
        'bag_count' => 10, 'kg_per_bag' => '50']);

    Livewire::test(ManageGatePasses::class)
        ->mountTableAction('whatsapp', $pass)
        ->assertTableActionDataSet(['phone' => '+923001234567', 'message' => 'Assalam o alaikum '.$this->f->seller->name.', your gate pass '.$pass->serial.' is attached.'])
        ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Gate pass sent on WhatsApp.');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media') && $r->isMultipart());
    // No document template: a plain document with the message as its caption.
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === '923001234567' && $r['type'] === 'document'
        && $r['document']['id'] === 'MEDIA-1' && $r['document']['filename'] === ManageGatePasses::fileName($pass)
        && str_starts_with($r['document']['caption'], 'Assalam o alaikum'));

    $kept = PartyMessage::query()->where('event', 'document')->sole();
    expect($kept->status)->toBe(PartyMessage::SENT)->and($kept->provider_message_id)->toBe('wamid.FILE1')->and($kept->body)->toContain('📎');
});

it('sends a file as the approved document template when one is set, so it reaches anyone', function () {
    wafFake();
    config(['ricemill.messaging.whatsapp_cloud.doc_template' => 'mill_document']);
    wafSignIn($this, $this->f->owner);

    $r = app(WhatsAppFiles::class)->send($this->f->seller, '03001234567', '%PDF-1.4 test', 'slip.pdf', "Dear X,\nyour slip", 'weighment slip');
    expect($r['ok'])->toBeTrue();
    Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/messages') && $q['type'] === 'template' && $q['template']['name'] === 'mill_document'
        && $q['template']['components'][0]['parameters'][0]['document']['id'] === 'MEDIA-1'
        && $q['template']['components'][1]['parameters'][0]['text'] === 'Dear X, your slip');
});

it('explains the 24-hour rule when WhatsApp refuses a file without a template', function () {
    Http::fake([
        'graph.facebook.com/v21.0/1234567890/media' => Http::response(['id' => 'MEDIA-1']),
        'graph.facebook.com/v21.0/1234567890/messages' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400),
    ]);
    wafSignIn($this, $this->f->owner);

    $r = app(WhatsAppFiles::class)->send($this->f->seller, '03001234567', '%PDF-1.4', 'slip.pdf', 'Your slip', 'weighment slip');
    expect($r['ok'])->toBeFalse()->and($r['error'])->toContain('within 24 hours')
        ->and(PartyMessage::query()->where('event', 'document')->sole()->status)->toBe(PartyMessage::FAILED);
    expect(fn () => app(WhatsAppFiles::class)->send($this->f->seller, '12', '%PDF', 'a.pdf', 'x', 'slip'))->toThrow(BusinessRuleViolation::class, 'mobile number');
});

it('lets the Owner set the file message with the words in braces filled in', function () {
    wafSignIn($this, $this->f->owner);
    Livewire::test(IntegrationsPage::class)
        ->callAction('fileMessage', ['text' => '{mill}: {document} {number} for {party}'])
        ->assertNotified('Saved. Files sent on WhatsApp now carry this message.');

    expect(app(WhatsAppFiles::class)->message($this->f->entity->id, 'Ali Traders', 'invoice', 'INV-1'))->toEndWith(': invoice INV-1 for Ali Traders');
});
