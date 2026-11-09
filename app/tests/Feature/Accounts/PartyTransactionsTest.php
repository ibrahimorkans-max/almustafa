<?php

declare(strict_types=1);

use App\Domain\Accounts\PurchaseBillService;
use App\Filament\Pages\PartyTransactionsPage;
use App\Models\Accounts\Voucher;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('PTX'));
    $entry = $this->f->receiveLot();
    $this->f->as($this->f->accountant);
    $this->bill = app(PurchaseBillService::class)->post($this->f->billFor($entry));
    $this->seller = $this->f->inv->gate->seller;
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->f->owner, 'web')->withSession(Make::webSession($this->f->owner));
    $this->f->as($this->f->owner);
});

it("shows every entry on a party's account like a bank statement, with the balance after each", function () {
    $page = Livewire::test(PartyTransactionsPage::class)->assertOk()->assertSee('Choose a party')
        ->set('party', $this->seller->id)->set('period', 'all');

    $a = $page->instance()->account();
    $number = Voucher::query()->find($this->bill->voucher_id)->number;
    expect($a['lines'])->not->toBeEmpty()->and($a['closing'])->toBe($a['opening'] + $a['total_dr'] - $a['total_cr'])
        ->and(collect($a['lines'])->pluck('number'))->toContain($number);
    $page->assertSee($number)->assertSee('Purchase bill')->assertSee(PartyTransactionsPage::balance($a['closing']))
        ->set('direction', 'out')->assertDontSee('Purchase bill')
        ->set('direction', '')->set('search', 'no-such-entry')->assertSee('No transactions in this period.');

    // Opened from the party's page.
    Livewire::withQueryParams(['party' => $this->seller->id])->test(PartyTransactionsPage::class)->assertSet('party', $this->seller->id);
});
