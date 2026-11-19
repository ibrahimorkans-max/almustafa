<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Reports\Balances;
use App\Domain\Sales\QuickBills;
use App\Filament\Support\MillingWeights;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('BAL'));
    $this->f->as($this->f->dispatcher);
});

it('lists who owes the mill and whom the mill owes, from the books', function () {
    app(QuickBills::class)->create(['kind' => 'sale', 'party_id' => $this->f->multanBuyer->id,
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '400', 'rate' => '9000']]]);   // Rs 90,000 owed
    app(QuickBills::class)->create(['kind' => 'purchase', 'party_id' => $this->f->inv->gate->seller->id,
        'lines' => [['product_id' => $this->f->inv->paddy->id, 'godown_id' => $this->f->inv->silo->id, 'gross_kg' => '800', 'rate' => '4000']]]);   // the mill owes Rs 80,000

    $rows = collect(app(Balances::class)->parties());
    expect($rows->firstWhere('party_id', $this->f->multanBuyer->id))->toMatchArray(['balance_paise' => 90_000_00, 'unpaid_bills' => 1])
        ->and($rows->firstWhere('party_id', $this->f->inv->gate->seller->id)['balance_paise'])->toBe(-80_000_00)
        ->and(collect(app(Balances::class)->parties(null, 'owe_us'))->pluck('party_id')->all())->toBe([$this->f->multanBuyer->id])
        ->and(collect(app(Balances::class)->parties('multan'))->pluck('party_id')->all())->toBe([$this->f->multanBuyer->id]);

    $this->actingAs($this->f->acc->owner, 'web')->withSession(Make::webSession($this->f->acc->owner));
    $this->get('/admin/party-balances')->assertOk()->assertSee('Multan Traders');
});

it('shows paddy and rice stock: opening, in, out and closing for the dates', function () {
    app(QuickBills::class)->create(['kind' => 'sale', 'customer_name' => 'Walk-in', 'vehicle_type' => 'motorcycle',
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '100', 'rate' => '9000']],
        'payment' => ['amount' => '22500', 'mode' => 'cash', 'ledger_id' => app(ChartOfAccounts::class)->system('cash')->id]]);

    $today = now('Asia/Karachi')->toDateString();
    $rice = collect(app(Balances::class)->stock($today, $today, 'rice'))->firstWhere('product', 'Rice 1121 (head)');
    expect($rice)->toMatchArray(['opening_kg' => '0.000', 'in_kg' => '20000.000', 'out_kg' => '100.000', 'closing_kg' => '19900.000'])
        ->and(app(Balances::class)->stock($today, $today, 'paddy'))->toBe([]);
    $tomorrow = now('Asia/Karachi')->addDay()->toDateString();
    expect(collect(app(Balances::class)->stock($tomorrow, $tomorrow, 'rice'))->first())->toMatchArray(['opening_kg' => '19900.000', 'in_kg' => '0.000']);

    $this->actingAs($this->f->acc->owner, 'web')->withSession(Make::webSession($this->f->acc->owner));
    $this->get('/admin/stock-report')->assertOk()->assertSee('Rice 1121 (head)');
});

it('works out milling net weight: scale − bags × bardana − other deduction', function () {
    expect(MillingWeights::net('10250', 200, '0.5', '50'))->toBe('10100.000')
        ->and(MillingWeights::net('', 10, '0.5', '0'))->toBeNull()
        ->and(MillingWeights::net('5', 20, '0.5', '0'))->toBeNull()
        ->and(MillingWeights::note(['scale_kg' => '10250', 'bags' => '200', 'bardana_kg' => '0.5', 'other_deduction_kg' => '50', 'quantity_kg' => '10100'], 'Shift A'))
        ->toBe("Shift A\nWeighed 10250 kg, 200 bags × 0.5 kg bardana, 50 kg other deduction → 10100 kg net");
});
