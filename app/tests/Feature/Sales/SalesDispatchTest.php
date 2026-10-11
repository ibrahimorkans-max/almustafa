<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountReports;
use App\Domain\Accounts\ManualVouchers;
use App\Domain\Approvals\Actions\VoucherReversal;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Gate\GateService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Sales\CreditControl;
use App\Domain\Sales\SalesBillService;
use App\Domain\Sales\SalesTaxCalculator;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\VoucherLine;
use App\Models\ApprovalRequest;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Inventory\StockEntry;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;
use App\Support\Values\Weight;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('SAL'));
});

it('computes sales tax and further tax per maund with half-up rounding', function () {
    $tax = new SalesTaxCalculator;

    expect($tax->calculate('10050', 160000, 500))   // 251.25 maund × Rs 1,600
        ->toBe(['taxable_paise' => 40_200_000, 'sales_tax_paise' => 2_010_000, 'further_tax_paise' => 0, 'round_off_paise' => 0, 'total_paise' => 42_210_000])
        ->and($tax->calculate('10050', 160000, 500, 400))->toMatchArray(['further_tax_paise' => 1_608_000, 'total_paise' => 43_818_000])
        // Rs 10.10 value: 5 % = 50.5 paisa → 51; 4 % = 40.4 → 40 (half-up)
        ->and($tax->calculate('0.2525', 160000, 500, 400, false))->toMatchArray(['taxable_paise' => 1010, 'sales_tax_paise' => 51, 'further_tax_paise' => 40, 'total_paise' => 1101])
        ->and($tax->calculate('0.2525', 160000, 500, 400))->toMatchArray(['total_paise' => 1100, 'round_off_paise' => -1])
        ->and($tax->calculate('10050', 160000, 0))->toMatchArray(['sales_tax_paise' => 0, 'total_paise' => 40_200_000]);
});

it('takes sales orders only within the sauda and the godown stock', function () {
    $purchase = $this->f->acc->sauda;
    expect(fn () => $this->f->order($purchase))->toThrow(BusinessRuleViolation::class, 'sales saudas');
    expect(fn () => $this->f->order(kg: '20000.001'))->toThrow(BusinessRuleViolation::class, 'holds only');

    $this->f->as($this->f->acc->owner);
    app(StockLedger::class)->post($this->f->inv->rice, $this->f->inv->store, Weight::kg('40000'), StockEntry::RECEIPT);
    $this->f->order(kg: '30000');
    $this->f->order(kg: '22500');   // 50,000 + 5 % = 52,500 in all
    expect(fn () => $this->f->order(kg: '1'))->toThrow(BusinessRuleViolation::class, 'left to order');
});

it('holds an order over the credit limit until a different person overrides', function () {
    $order = $this->f->order(kg: '12000'); // Rs 504,000 incl. sales tax > Rs 500,000 limit
    expect($order->status)->toBe(DispatchOrder::PENDING_CREDIT)->and($order->credit_override_request_id)->not->toBeNull();

    $request = ApprovalRequest::query()->findOrFail($order->credit_override_request_id);
    expect(app(ApprovalService::class)->canDecide($this->f->dispatcher, $request))->toBeFalse();
    app(ApprovalService::class)->approve($request, $this->f->acc->owner);
    expect($order->fresh()->status)->toBe(DispatchOrder::OPEN);

    $second = $this->f->order(kg: '1000');
    app(ApprovalService::class)->reject(ApprovalRequest::query()->findOrFail($second->credit_override_request_id), $this->f->acc->owner, 'Collect dues first');
    expect($second->fresh()->status)->toBe(DispatchOrder::CANCELLED);
});

it('dispatches through the gate: stock out, delivery on the sauda, invoice, gate pass, exit', function () {
    $order = $this->f->order();
    $entry = $this->f->loadAndWeigh($order);

    $bill = $this->f->billFor($entry);
    expect($entry->direction)->toBe('outbound')->and($entry->net_kg)->toBe('10050.000')
        ->and($order->fresh()->status)->toBe(DispatchOrder::DISPATCHED)
        ->and(app(StockLedger::class)->balance($this->f->inv->rice, $this->f->inv->store)->kg)->toBe('9950.000')
        ->and($this->f->sauda->fresh()->delivered_kg)->toBe('10050.000')
        ->and($bill->status)->toBe('draft')
        ->and($bill)->sales_tax_paise->toBe(2_010_000)->total_paise->toBe(42_210_000)->buyer_strn->toBe(SalesFixtures::strn(2))->pct_code->toBe('1006.3000');

    $gate = app(GateService::class);
    expect(fn () => $gate->exit($entry))->toThrow(BusinessRuleViolation::class, 'gate pass');
    expect(fn () => $gate->issueGatePass($entry))->toThrow(BusinessRuleViolation::class, 'invoice must be posted');

    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($bill);
    $lines = VoucherLine::query()->where('voucher_id', $bill->voucher_id)->with('account')->orderBy('line_no')->get()
        ->map(fn ($l) => [$l->account->code, $l->dr_paise, $l->cr_paise])->all();
    expect($bill->number)->toMatch('#^SAL/INV/\d{4}-\d{2}/0001$#')
        ->and($lines)->toBe([['P-C-KHI', 42_210_000, 0], ['SAL-RICE', 0, 40_200_000], ['ST-OUTPUT', 0, 2_010_000]]);

    $this->f->as($this->f->dispatcher);
    $pass = $gate->issueGatePass($entry);
    $gate->exit($entry);
    expect($pass->data['invoice_number'])->toBe($bill->number)->and($pass->data['number'])->toContain('/GP/')
        ->and($entry->fresh()->status)->toBe('exited');
});

it('charges the same single sales tax to a registered buyer in any province', function () {
    $order = $this->f->order($this->f->saleSauda($this->f->multanBuyer));
    $bill = $this->f->billFor($this->f->loadAndWeigh($order));

    expect($bill)->sales_tax_paise->toBe(2_010_000)->further_tax_paise->toBe(0)->total_paise->toBe(42_210_000)
        ->and($bill->snapshot['buyer'])->toMatchArray(['province' => 'Punjab', 'registered' => true]);
});

it('refuses the slip when the godown no longer holds the goods', function () {
    $order = $this->f->order();
    $this->f->as($this->f->acc->owner);
    app(StockLedger::class)->transfer($this->f->inv->rice, $this->f->inv->store, $this->f->inv->silo, Weight::kg('15000'));

    expect(fn () => $this->f->loadAndWeigh($order))->toThrow(BusinessRuleViolation::class, 'Not enough');
    $entry = GateEntry::query()->sole();
    expect(GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'slip_issued')->exists())->toBeFalse()
        ->and(SalesBill::query()->count())->toBe(0);
});

it('flags loads outside the order tolerance and bag-weight mismatches', function () {
    $order = $this->f->order(kg: '10000', bags: 400, bagKg: '25');
    $entry = $this->f->loadAndWeigh($order, '9300', 400); // 7 % short; 400 × 25 = 10,000 ≠ 9,300

    expect($entry->flags)->toContain('quantity_out_of_tolerance', 'bag_weight_mismatch');
});

it('settles invoices by receipts, shows receivables ageing and frees credit', function () {
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($this->f->billFor($entry));
    $credit = app(CreditControl::class);
    expect($credit->exposure($this->f->karachiBuyer))->toBe(42_210_000);

    $receipts = app(ManualVouchers::class);
    expect(fn () => $receipts->receipt($this->f->karachiBuyer, $this->f->acc->bank, 50_000_000, now(), null, [$bill->id => 42_210_001]))
        ->toThrow(BusinessRuleViolation::class, 'outstanding');
    $receipts->receipt($this->f->karachiBuyer, $this->f->acc->bank, 30_000_000, now(), 'NEFT', [$bill->id => 30_000_000]);

    $ageing = app(AccountReports::class)->receivablesAgeing(now()->addDays(45));
    expect(app(SalesBillService::class)->outstanding($bill->fresh()))->toBe(12_210_000)
        ->and($ageing[0])->toMatchArray(['received' => 30_000_000, 'outstanding' => 12_210_000, 'bucket' => '0–30'])
        ->and($credit->exposure($this->f->karachiBuyer))->toBe(12_210_000);
});

it('reverses an unpaid invoice through a second person', function () {
    $entry = $this->f->loadAndWeigh($this->f->order());
    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($this->f->billFor($entry));

    $request = app(ApprovalService::class)->submit(VoucherReversal::KEY, ['voucher_id' => $bill->voucher_id, 'reason' => 'Wrong buyer selected'], 'Reverse');
    app(ApprovalService::class)->approve($request, $this->f->acc->owner);

    expect($bill->fresh()->status)->toBe(SalesBill::CANCELLED)
        ->and(app(CreditControl::class)->exposure($this->f->karachiBuyer))->toBe(0);
});

it('posts a commercial invoice without a tax line while the mill has no STRN', function () {
    app(TenantContext::class)->runAsSystem(fn () => Entity::query()->findOrFail($this->f->entity->id)->forceFill(['strn' => null])->save());
    $bill = $this->f->billFor($this->f->loadAndWeigh($this->f->order()));
    $this->f->as($this->f->acc->accountant);
    $bill = app(SalesBillService::class)->post($bill);
    $codes = VoucherLine::query()->where('voucher_id', $bill->voucher_id)->with('account')->get()->map(fn ($l) => $l->account->code)->all();

    expect($bill)->invoice_type->toBe('commercial')->seller_strn->toBeNull()->total_paise->toBe(40_200_000)
        ->and($codes)->toBe(['P-C-KHI', 'SAL-RICE']);
});
