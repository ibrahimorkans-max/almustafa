<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Filament\Pages\BankAccountsPage;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\Accounts\LedgerAccount;
use App\Models\AuthSession;
use App\Support\Values\PakistanIds;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('BNK'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $web = Make::webSession($this->f->owner);
    $this->actingAs($this->f->owner, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($this->f->owner, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
});

it("adds the mill's bank account with a simple form: bank, title and IBAN (kept encrypted)", function () {
    $iban = PakistanIds::ibanFor('MEZN', '0000001234567890');

    Livewire::test(BankAccountsPage::class)->assertOk()
        ->callAction('add', ['bank_name' => 'Meezan Bank', 'title' => 'Current account – Lodhran', 'iban' => 'PK00 MEZN 0000 0000 0000 0000'])
        ->assertHasActionErrors(['iban'])
        ->callAction('add', ['bank_name' => 'Meezan Bank', 'title' => 'Current account – Lodhran', 'iban' => $iban])
        ->assertHasNoActionErrors()->assertNotified('Bank account added: Meezan Bank — Current account – Lodhran');

    $ledger = LedgerAccount::query()->where('name', 'Meezan Bank — Current account – Lodhran')->sole();
    expect($ledger->kind)->toBe('bank')->and($ledger->group->code)->toBe('BANK_ACCOUNTS')
        ->and($ledger->code)->toStartWith('BANK-MEEZAN-')->and($ledger->bank_account_last4)->toBe(substr($iban, -4))
        ->and($ledger->getRawOriginal('bank_account_number'))->not->toContain('1234567890');

    // The same IBAN twice is refused; the account is listed for payments and receipts.
    Livewire::test(BankAccountsPage::class)
        ->callAction('add', ['bank_name' => 'Meezan Bank', 'title' => 'Again', 'iban' => $iban])->assertNotified('This IBAN is already one of the mill\'s bank accounts.')
        ->assertCanSeeTableRecords([$ledger]);
});
