<?php

declare(strict_types=1);

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\RecordDocuments;
use App\Domain\Inventory\StockLedger;
use App\Domain\Sales\DispatchService;
use App\Domain\Sales\QuickBills;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Sales\QuickBillResource\Pages\CreateQuickBill;
use App\Filament\Resources\Sales\QuickBillResource\Pages\ListQuickBills;
use App\Models\Accounts\Voucher;
use App\Models\Accounts\VoucherLine;
use App\Models\Sales\QuickBill;
use Livewire\Livewire;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('QB'));
    $this->f->as($this->f->dispatcher);
    $this->cash = fn () => app(ChartOfAccounts::class)->system('cash')->id;
});

/** @return list<array{0: string, 1: int, 2: int}> the voucher's lines: [ledger code, debit, credit] */
function qbVoucher(?string $voucherId): array
{
    return VoucherLine::query()->where('voucher_id', $voucherId)->with('account')->orderBy('line_no')->get()
        ->map(fn ($l) => [$l->account->code, (int) $l->dr_paise, (int) $l->cr_paise])->all();
}

it('works out net weight and amount: weight − empty − bags × deduction − other, ÷ 40 × rate', function () {
    expect(QuickBills::lineAmount('105', '0', 2, '0.5', '0', 800_000))->toBe(['net_kg' => '104.000', 'amount_paise' => 2_080_000])
        ->and(QuickBills::lineAmount('15250', '0', 400, '0.6', '228.75', 100_000))->toBe(['net_kg' => '14781.250', 'amount_paise' => 36_953_125])
        ->and(QuickBills::lineAmount('10', '1', null, '0', '0', 333))->toBe(['net_kg' => '9.000', 'amount_paise' => 75]);   // 74.925 → 75
});

it('sells two products to a walk-in customer on a bike, with vehicle rent, paid in cash', function () {
    $this->f->inv->stock($this->f->inv->broken, $this->f->inv->store, '500');
    $data = [
        'kind' => 'sale', 'billed_at' => now('Asia/Karachi')->format('Y-m-d H:i'), 'customer_name' => 'Aslam', 'customer_phone' => '0300-1112223',
        'vehicle_type' => 'motorcycle', 'vehicle_no' => 'lrx-123',
        'lines' => [
            ['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'bags' => 2, 'gross_kg' => '105', 'deduction_per_bag_kg' => '0.5', 'rate' => '8000'],
            ['product_id' => $this->f->inv->broken->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '41', 'rate' => '4000'],
        ],
        'charges' => [['label' => 'Vehicle rent', 'effect' => 'add', 'amount' => '300']],
    ];

    // A walk-in customer pays in full.
    expect(fn () => app(QuickBills::class)->create($data + ['payment' => ['amount' => '1000', 'mode' => 'cash', 'ledger_id' => ($this->cash)()]]))
        ->toThrow(BusinessRuleViolation::class, 'walk-in customer pays the whole bill');

    $bill = app(QuickBills::class)->create($data + ['payment' => ['amount' => '25200', 'mode' => 'cash', 'ledger_id' => ($this->cash)()]]);

    expect($bill->number)->toMatch('#^QB/QB/\d{4}-\d{2}/0001$#')
        ->and($bill->net_paise)->toBe(2_520_000)->and($bill->paymentStatus())->toBe('paid')
        ->and($bill->vehicle_no)->toBe('LRX-123')
        ->and($bill->lines()->pluck('description')->all())->toBe(['Rice 1121 (head)', 'Broken rice'])
        ->and(app(StockLedger::class)->balance($this->f->inv->rice, $this->f->inv->store)->kg)->toBe('19896.000')
        ->and(app(StockLedger::class)->balance($this->f->inv->broken, $this->f->inv->store)->kg)->toBe('459.000')
        ->and(qbVoucher($bill->voucher_id))->toBe([
            ['SAL-RICE', 0, 2_080_000], ['SAL-BYPRODUCT', 0, 410_000], ['INC-CHARGES', 0, 30_000], ['WALK-IN', 2_520_000, 0],
        ])
        ->and(Voucher::query()->where('source_type', 'quick_bill')->where('type', 'receipt')->count())->toBe(1);
});

it('keeps a party\'s bill unpaid, then takes a part by cheque and the rest in cash', function () {
    $bill = app(QuickBills::class)->create([
        'kind' => 'sale', 'party_id' => $this->f->multanBuyer->id, 'vehicle_type' => 'truck', 'vehicle_no' => 'TLA-9',
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '4000', 'tare_kg' => '0', 'rate' => '9000']],
    ]);
    expect($bill->net_paise)->toBe(90_000_000)->and($bill->paymentStatus())->toBe('unpaid');

    expect(fn () => app(QuickBills::class)->settlePayment($bill, ['amount' => '500000', 'mode' => 'cheque', 'ledger_id' => $this->f->acc->bank->id]))
        ->toThrow(BusinessRuleViolation::class, 'cheque');
    app(QuickBills::class)->settlePayment($bill, ['amount' => '500000', 'mode' => 'cheque', 'ledger_id' => $this->f->acc->bank->id, 'reference' => 'CHQ-001122']);
    expect($bill->fresh()->paymentStatus())->toBe('partial')->and($bill->fresh()->balancePaise())->toBe(40_000_000)
        ->and(fn () => app(QuickBills::class)->settlePayment($bill->fresh(), ['amount' => '400001', 'mode' => 'cash', 'ledger_id' => ($this->cash)()]))
        ->toThrow(BusinessRuleViolation::class, 'left to pay');
    app(QuickBills::class)->settlePayment($bill->fresh(), ['amount' => '400000', 'mode' => 'cash', 'ledger_id' => ($this->cash)()]);
    expect($bill->fresh()->paymentStatus())->toBe('paid')->and($bill->payments()->count())->toBe(2);
});

it('buys paddy with deductions typed by hand into the silo, on credit', function () {
    $bill = app(QuickBills::class)->create([
        'kind' => 'purchase', 'party_id' => $this->f->inv->gate->seller->id, 'vehicle_type' => 'tractor_trolley',
        'lines' => [['product_id' => $this->f->inv->paddy->id, 'godown_id' => $this->f->inv->silo->id, 'bags' => 400, 'gross_kg' => '15250',
            'deduction_per_bag_kg' => '0.6', 'other_deduction_kg' => '228.75', 'rate' => '1000']],
        'charges' => [['label' => 'Labour (mazdoori)', 'effect' => 'less', 'amount' => '500']],
    ]);

    expect($bill->in_paise)->toBe(36_953_125)->and($bill->net_paise)->toBe(-36_953_125 - 50_000)->and($bill->paymentStatus())->toBe('unpaid')
        ->and(app(StockLedger::class)->balance($this->f->inv->paddy, $this->f->inv->silo)->kg)->toBe('14781.250')
        ->and(qbVoucher($bill->voucher_id))->toBe([['PUR-PADDY', 36_953_125, 0], ['EXP-SELLING', 50_000, 0], ['P-'.$this->f->inv->gate->seller->code, 0, 37_003_125]]);
});

it('gives rice for paddy on one bill, and bills milling labour and a weighing', function () {
    $exchange = app(QuickBills::class)->create([
        'kind' => 'exchange', 'party_id' => $this->f->multanBuyer->id,
        'lines' => [
            ['direction' => 'in', 'product_id' => $this->f->inv->paddy->id, 'godown_id' => $this->f->inv->silo->id, 'gross_kg' => '400', 'rate' => '4000'],
            ['direction' => 'out', 'product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '200', 'rate' => '9000'],
        ],
    ]);
    expect($exchange->in_paise)->toBe(4_000_000)->and($exchange->out_paise)->toBe(4_500_000)->and($exchange->net_paise)->toBe(500_000);
    expect(fn () => app(QuickBills::class)->create(['kind' => 'exchange', 'party_id' => $this->f->multanBuyer->id,
        'lines' => [['direction' => 'out', 'product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '10', 'rate' => '9000']]]))
        ->toThrow(BusinessRuleViolation::class, 'paddy taken');

    $milling = app(QuickBills::class)->create([
        'kind' => 'milling', 'customer_name' => 'Rafiq (zamindar)', 'vehicle_type' => 'by_hand',
        'lines' => [['description' => 'Paddy milled (customer\'s own)', 'gross_kg' => '800', 'rate' => '150']],
        'payment' => ['amount' => '3000', 'mode' => 'cash', 'ledger_id' => ($this->cash)()],
    ]);
    expect($milling->service_paise)->toBe(300_000)->and(qbVoucher($milling->voucher_id))->toBe([['INC-MILLING', 0, 300_000], ['WALK-IN', 300_000, 0]])
        ->and(app(StockLedger::class)->balance($this->f->inv->rice, $this->f->inv->store)->kg)->toBe('19800.000');   // milling moves no stock

    $weighing = app(QuickBills::class)->create([
        'kind' => 'weighing', 'customer_name' => 'Outside truck', 'vehicle_type' => 'motorcycle', 'first_weight_kg' => '310', 'second_weight_kg' => '140',
        'lines' => [['description' => 'Weighing fee', 'gross_kg' => '40', 'rate' => '100']],
        'payment' => ['amount' => '100', 'mode' => 'cash', 'ledger_id' => ($this->cash)()],
    ]);
    expect($weighing->first_weight_kg)->toBe('310.000')->and(qbVoucher($weighing->voucher_id)[0])->toBe(['INC-WEIGHING', 0, 10_000]);

    // The printed bill: date and time, rates, PAID / UNPAID.
    expect(app(RecordDocuments::class)->pdf('quick_bill', $exchange))->toStartWith('%PDF');
});

it('opens the quick bill screens', function () {
    $this->actingAs($this->f->acc->owner, 'web')->withSession(Make::webSession($this->f->acc->owner));
    $this->get('/admin/quick-bills')->assertOk();
    $this->get('/admin/quick-bills/create')->assertOk()->assertSee('Products sold');
    $bill = app(QuickBills::class)->create(['kind' => 'sale', 'customer_name' => 'Walk-in', 'vehicle_type' => 'by_hand',
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '10', 'rate' => '9000']],
        'payment' => ['amount' => '2250', 'mode' => 'cash', 'ledger_id' => ($this->cash)()]]);
    $this->get('/admin/quick-bills/'.$bill->id)->assertOk()->assertSee($bill->number)->assertSee('Paid');
    expect(QuickBill::query()->count())->toBe(1);
});

it('fills the rate when a product is picked, and saves the form as an unpaid bill', function () {
    $this->actingAs($this->f->acc->owner, 'web')->withSession(Make::webSession($this->f->acc->owner));
    app(AuthContextBinder::class)->bind($this->f->acc->owner);

    $form = Livewire::test(CreateQuickBill::class)
        ->fillForm(['kind' => 'sale', 'party_id' => $this->f->multanBuyer->id, 'vehicle_type' => 'pickup', 'vehicle_no' => 'ABC-1'])
        ->set('data.lines', ['l1' => ['product_id' => null, 'description' => null, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '80', 'tare_kg' => '0',
            'deduction_per_bag_kg' => '0', 'other_deduction_kg' => '0', 'rate' => null]])
        ->set('data.lines.l1.product_id', $this->f->inv->rice->id)
        ->assertSet('data.lines.l1.description', 'Rice 1121 (head)')
        ->set('data.lines.l1.rate', '9500')
        ->set('data.charges', ['c1' => ['label' => 'Vehicle rent', 'effect' => 'add', 'amount' => '200']])
        ->call('create')->assertHasNoFormErrors();

    $bill = QuickBill::query()->sole();
    expect($bill->net_paise)->toBe(1_900_000 + 20_000)->and($bill->paymentStatus())->toBe('unpaid')->and($bill->party_id)->toBe($this->f->multanBuyer->id);
});

it('counts a bill on a sauda against the deal and refuses more than is left', function () {
    $sauda = $this->f->saleSauda($this->f->karachiBuyer, '2000');   // 2,000 kg + 5 % tolerance = 2,100 kg
    $this->f->as($this->f->dispatcher);
    $bill = app(QuickBills::class)->create(['kind' => 'sale', 'sauda_id' => $sauda->id, 'vehicle_type' => 'motorcycle',
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '1500', 'rate' => '1600']]]);

    expect($bill->party_id)->toBe($this->f->karachiBuyer->id)->and($bill->sauda_kg)->toBe('1500.000')
        ->and($sauda->fresh())->delivered_kg->toBe('1500.000')->status->toBe('in_delivery')
        ->and(fn () => app(QuickBills::class)->create(['kind' => 'sale', 'sauda_id' => $sauda->id,
            'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '700', 'rate' => '1600']]]))
        ->toThrow(BusinessRuleViolation::class, 'is left')
        ->and(fn () => app(QuickBills::class)->create(['kind' => 'sale', 'sauda_id' => $sauda->id, 'party_id' => $this->f->multanBuyer->id,
            'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '10', 'rate' => '1600']]]))
        ->toThrow(BusinessRuleViolation::class, 'another party');
    app(QuickBills::class)->create(['kind' => 'sale', 'sauda_id' => $sauda->id,
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '500', 'rate' => '1600']]]);
    expect($sauda->fresh()->status)->toBe('completed');
});

it('cancels a bill: voucher and receipts reversed, stock back, sauda quantity back', function () {
    $sauda = $this->f->saleSauda($this->f->karachiBuyer, '2000');
    $this->f->as($this->f->dispatcher);
    $bill = app(QuickBills::class)->create(['kind' => 'sale', 'sauda_id' => $sauda->id,
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '2000', 'rate' => '1600']],
        'payment' => ['amount' => '30000', 'mode' => 'cash', 'ledger_id' => ($this->cash)()]]);
    expect($sauda->fresh()->status)->toBe('completed')
        ->and(app(StockLedger::class)->balance($this->f->inv->rice, $this->f->inv->store)->kg)->toBe('18000.000')
        ->and(fn () => app(QuickBills::class)->cancel($bill, 'Typed wrong'))->toThrow(BusinessRuleViolation::class, 'voucher.reverse');

    $this->f->as($this->f->acc->owner);
    expect(fn () => app(QuickBills::class)->cancel($bill, 'x'))->toThrow(BusinessRuleViolation::class, 'reason');
    $bill = app(QuickBills::class)->cancel($bill, 'Typed the wrong weight');

    $party = app(ChartOfAccounts::class)->partyLedger($this->f->karachiBuyer);
    expect($bill->status)->toBe(QuickBill::CANCELLED)->and($bill->cancel_reason)->toBe('Typed the wrong weight')
        ->and(Voucher::query()->whereNotNull('reverses_id')->where('source_id', $bill->id)->count())->toBe(2)   // the bill and the receipt
        ->and(app(Books::class)->balance($party))->toBe(0)
        ->and(app(StockLedger::class)->balance($this->f->inv->rice, $this->f->inv->store)->kg)->toBe('20000.000')
        ->and($sauda->fresh())->delivered_kg->toBe('0.000')->status->toBe('in_delivery')
        ->and(fn () => app(QuickBills::class)->cancel($bill, 'Again please'))->toThrow(BusinessRuleViolation::class, 'already cancelled')
        ->and(fn () => app(QuickBills::class)->settlePayment($bill, ['amount' => '1', 'mode' => 'cash', 'ledger_id' => ($this->cash)()]))
        ->toThrow(BusinessRuleViolation::class, 'cancelled');
});

it('dispatches directly on a dispatch order — by hand, weights typed, at the sauda rate — with its bill', function () {
    $sauda = $this->f->saleSauda($this->f->karachiBuyer, '2000');
    $this->f->inv->stock($this->f->inv->broken, $this->f->inv->store, '100');
    $this->f->as($this->f->dispatcher);
    $order = app(DispatchService::class)->createDirect($sauda, [
        'vehicle_type' => 'by_hand', 'billed_at' => now('Asia/Karachi')->format('Y-m-d H:i'),
        'lines' => [
            ['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'bags' => 4, 'gross_kg' => '101', 'deduction_per_bag_kg' => '0.25'],
            ['product_id' => $this->f->inv->broken->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '40', 'rate' => '900'],
        ],
    ]);
    $bill = $order->quickBill;
    expect($order->status)->toBe('dispatched')->and($order->quantity_kg)->toBe('100.000')->and($order->vehicle_type)->toBe('by_hand')
        ->and($bill->lines()->pluck('rate_paise_per_maund')->all())->toBe([160_000, 90_000])   // the sauda's rate for its product
        ->and($bill->net_paise)->toBe(400_000 + 90_000)
        ->and($sauda->fresh()->delivered_kg)->toBe('100.000');

    // Over the credit limit (Rs 500,000): refused unless paid now.
    expect(fn () => app(DispatchService::class)->createDirect($sauda, [
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '1990', 'rate' => '12000']]]))
        ->toThrow(BusinessRuleViolation::class, 'credit limit');

    $this->actingAs($this->f->acc->owner, 'web')->withSession(Make::webSession($this->f->acc->owner));
    $this->get('/admin/dispatch-orders/'.$order->id)->assertOk()->assertSee($bill->number);
    $this->get('/admin/dispatch-orders/create')->assertOk()->assertSee('Direct');
});

it('finds bills by the party or walk-in customer in the search box', function () {
    $a = app(QuickBills::class)->create(['kind' => 'sale', 'party_id' => $this->f->multanBuyer->id,
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '40', 'rate' => '9000']]]);
    $b = app(QuickBills::class)->create(['kind' => 'sale', 'customer_name' => 'Aslam Bhatti', 'vehicle_type' => 'by_hand',
        'lines' => [['product_id' => $this->f->inv->rice->id, 'godown_id' => $this->f->inv->store->id, 'gross_kg' => '40', 'rate' => '9000']],
        'payment' => ['amount' => '9000', 'mode' => 'cash', 'ledger_id' => ($this->cash)()]]);
    $this->actingAs($this->f->acc->owner, 'web')->withSession(Make::webSession($this->f->acc->owner));
    app(AuthContextBinder::class)->bind($this->f->acc->owner);

    Livewire::test(ListQuickBills::class)
        ->searchTable('multan')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->searchTable('aslam')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
});
