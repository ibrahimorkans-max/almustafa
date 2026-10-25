<?php

declare(strict_types=1);

use App\Domain\Accounts\PaymentService;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Messaging\PartyAccounts;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\PartyMessagesPage;
use App\Filament\Pages\PartyPortalPage;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\PartyMessage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('MSG'));
    $this->seller = $this->f->inv->gate->seller;
    $this->f->as($this->f->owner);
    $this->seller->forceFill(['phone' => '+923001234567'])->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function msgSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

function messagesOf(Party $party, string $event): array
{
    app(AuthContextBinder::class)->bind(test()->f->owner);   // the gate helpers end without a firm

    return PartyMessage::query()->where('party_id', $party->id)->where('event', $event)->orderBy('created_at')->pluck('body')->all();
}

// ------------------------------------------------------------------ party logins

it('makes a party login, sends the details without keeping the password, and gives a new password on request', function () {
    $first = app(PartyAccounts::class)->grant($this->seller);

    $account = $first['account']->fresh(['role']);
    expect($first['new'])->toBeTrue()
        ->and($account->role->code)->toBe('PARTY')->and($account->must_change_password)->toBeTrue()
        ->and($this->seller->fresh()->login_account_id)->toBe($account->id)
        ->and($first['text'])->toContain('Username: *'.$first['username'].'*')->toContain('Password: *'.$first['password'].'*')
        ->toEndWith('Thank you for working with us.');
    $sent = PartyMessage::query()->where('event', 'login')->sole();
    expect($sent->status)->toBe(PartyMessage::MANUAL)->and($sent->redacted)->toBeTrue()->and($sent->body)->toBeNull()
        ->and($sent->recipient)->toBe('+923001234567');

    $again = app(PartyAccounts::class)->grant($this->seller->fresh());
    expect($again['new'])->toBeFalse()->and($again['account']->id)->toBe($account->id)->and($again['password'])->not->toBe($first['password']);
});

it('needs the party mobile number and the right to make logins', function () {
    $this->seller->forceFill(['phone' => null])->save();
    expect(fn () => app(PartyAccounts::class)->grant($this->seller->fresh()))->toThrow(BusinessRuleViolation::class, 'mobile number');

    $this->f->as(Make::account($this->f->entity, 'SUPERVISOR'));
    expect(fn () => app(PartyAccounts::class)->grant($this->seller->fresh()))->toThrow(BusinessRuleViolation::class, 'may not make party logins');
});

// ------------------------------------------------------------------ messages on events

it('tells the party of each money entry with its balance, of its vehicle unloaded and of its final weights', function () {
    $entry = $this->f->receiveLot();   // unloaded, weighed, slip issued
    $this->f->as($this->f->owner);

    expect(messagesOf($this->seller, 'unloaded')[0])->toContain('*Your vehicle was unloaded at the mill*')->toContain('Vehicle: *MNA-21-1234*')
        ->and(messagesOf($this->seller, 'weighed')[0])->toContain('Net weight: *15,250 kg (381.25 maund)*')->toEndWith('Thank you for working with us.');

    $this->f->as($this->f->accountant);
    $bill = app(PurchaseBillService::class)->post($this->f->billFor($entry));
    $money = messagesOf($this->seller, 'money');
    expect($money[0])->toStartWith('*Al Mustafa Rice Mills*
*Purchase bill for your goods*')->toContain($bill->number ?? '')
        ->toContain('Balance: *We owe you Rs')->toEndWith('Thank you for working with us.');

    // Paid in full (entered by the clerk, released by the Owner): the party hears it and that the account is settled.
    $this->f->as($this->f->accountant);
    $payment = app(PaymentService::class)->request($this->seller, 'ibft', $this->f->bank, $bill->net_paise, now(), [$bill->id => $bill->net_paise]);
    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($payment->approval_request_id), $this->f->owner);
    $money = messagesOf($this->seller, 'money');
    expect(end($money))->toContain('Payment made to you')->toContain('Your account is settled');
});

it('sends nothing of a kind switched off, and keeps a switched-off party out', function () {
    app(SettingsService::class)->put('messages.unloaded', 'off', $this->f->entity->id);
    $this->seller->forceFill(['messages_on' => false])->save();
    $this->f->receiveLot();
    $this->f->as($this->f->owner);

    expect(PartyMessage::query()->where('event', 'unloaded')->count())->toBe(0);
    $weighed = PartyMessage::query()->where('event', 'weighed')->sole();
    expect($weighed->status)->toBe(PartyMessage::SKIPPED)->and($weighed->error)->toContain('switched off for this party');
});

it('sends through the gateway when one is set up', function () {
    config(['ricemill.messaging.whatsapp_url' => 'https://gateway.test/send', 'ricemill.messaging.whatsapp_token' => 'tok']);
    Http::fake(['gateway.test/*' => Http::response(['ok' => true])]);
    $this->f->receiveLot();
    $this->f->as($this->f->owner);

    $sent = PartyMessage::query()->where('event', 'weighed')->sole();
    expect($sent->status)->toBe(PartyMessage::SENT)->and($sent->sent_at)->not->toBeNull();
    Http::assertSent(fn ($r) => $r->url() === 'https://gateway.test/send' && $r['to'] === '+923001234567'
        && str_contains($r['text'], 'Net weight: *15,250 kg (381.25 maund)*') && $r->hasHeader('Authorization', 'Bearer tok'));
});

// ------------------------------------------------------------------ screens

it('lets the office switch each kind of message and send waiting ones by hand', function () {
    $this->f->receiveLot();
    msgSignIn($this, $this->f->owner);

    Livewire::test(PartyMessagesPage::class)->assertSee('No gateway yet')->assertSee('to send by hand')
        ->set('events.money', false)->assertNotified()
        ->callTableAction('markSent', PartyMessage::query()->where('event', 'weighed')->sole())->assertNotified('Marked sent');
    expect(app(SettingsService::class)->messageOn('money', $this->f->entity->id))->toBeFalse()
        ->and(PartyMessage::query()->where('event', 'weighed')->sole()->status)->toBe(PartyMessage::SENT);
});

it('shows a party only its own account, vehicles and bills, and nothing else of the system', function () {
    $entry = $this->f->receiveLot();
    $this->f->as($this->f->accountant);
    app(PurchaseBillService::class)->post($this->f->billFor($entry));
    $this->f->as($this->f->owner);
    $login = app(PartyAccounts::class)->grant($this->seller)['account']->fresh(['role']);
    $login->forceFill(['must_change_password' => false])->save();

    msgSignIn($this, $login);
    $this->get('/admin')->assertRedirect(PartyPortalPage::getUrl());
    Livewire::test(PartyPortalPage::class)->assertSee('We owe you')->assertSee('MNA-21-1234')->assertSee('15,250')
        ->set('tab', 'bills')->assertSee('Purchase bill');
    foreach (['/admin/gate', '/admin/masters/parties', '/admin/payments', '/admin/purchase-bills', '/admin/live-weight', '/admin/party-messages'] as $path) {
        expect($this->get($path)->getStatusCode())->toBe(403, $path);
    }

    // Staff are not sent there.
    msgSignIn($this, $this->f->owner);
    expect(PartyPortalPage::canAccess())->toBeFalse();
});
