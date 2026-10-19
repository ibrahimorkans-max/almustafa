<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountsDocuments;
use App\Domain\Accounts\PaymentService;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Claims\ClaimService;
use App\Domain\Documents\DocumentVerifier;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Gate\GateService;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Sales\SalesBillService;
use App\Domain\Sales\SalesDocuments;
use App\Domain\Tax\SalesTaxReturns;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\VoucherLine;
use App\Models\ApprovalRequest;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Masters\Party;
use App\Models\Masters\TaxRate;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('TAX'));
    app(TenantContext::class)->runAsSystem(fn () => Entity::query()->findOrFail($this->f->entity->id)
        ->forceFill(['legal_name' => 'Al Mustafa Rice Mills', 'address' => 'Adda Permat, 1 km Shujabad Road', 'city' => 'Lodhran', 'province' => 'Punjab', 'phone' => '0300-6834588'])->save());
    $this->period = now('Asia/Karachi')->format('Y-m');
    $this->f->as($this->f->inv->storeManager);
    $this->f->inv->stock($this->f->inv->rice, $this->f->inv->store, '40000'); // room for several loads
});

function taxInvoiceFor(object $t, $sauda = null)
{
    $entry = $t->f->loadAndWeigh($t->f->order($sauda));
    $t->f->as($t->f->acc->accountant);

    return app(SalesBillService::class)->post($t->f->billFor($entry));
}

function walkInBuyer(object $t): Party
{
    $t->f->as($t->f->acc->owner);

    return Party::query()->create(['code' => 'C-WALK', 'name' => 'Walk-in buyer', 'is_customer' => true, 'city' => 'Lodhran', 'cnic' => '3620312345671']);
}

describe('sales tax on invoices', function () {
    it('charges the product rate to a registered buyer and posts it to output sales tax', function () {
        $bill = taxInvoiceFor($this); // 10,050 kg = 251.25 maund × Rs 1,600

        expect($bill)->invoice_type->toBe('sales_tax')->tax_rate_bp->toBe(500)->further_tax_paise->toBe(0)
            ->taxable_paise->toBe(40_200_000)->sales_tax_paise->toBe(2_010_000)->total_paise->toBe(42_210_000)
            ->seller_strn->toBe(SalesFixtures::strn(1))->buyer_strn->toBe(SalesFixtures::strn(2))->pct_code->toBe('1006.3000');
        $credit = VoucherLine::query()->where('voucher_id', $bill->voucher_id)->whereHas('account', fn ($q) => $q->where('code', 'ST-OUTPUT'))->sole();
        expect($credit->cr_paise)->toBe(2_010_000);
    });

    it('adds further tax for a buyer without an STRN', function () {
        $bill = taxInvoiceFor($this, $this->f->saleSauda(walkInBuyer($this), '20000'));

        expect($bill)->buyer_strn->toBeNull()->buyer_ntn->toBe('3620312345671')->further_tax_rate_bp->toBe(400)
            ->sales_tax_paise->toBe(2_010_000)->further_tax_paise->toBe(1_608_000)->total_paise->toBe(43_818_000);
        expect(VoucherLine::query()->where('voucher_id', $bill->voucher_id)->whereHas('account', fn ($q) => $q->where('code', 'ST-FURTHER'))->sole()->cr_paise)
            ->toBe(1_608_000);

        config(['ricemill.tax.further_tax_enabled' => false]);
        expect(taxInvoiceFor($this, $this->f->saleSauda(Party::query()->where('code', 'C-WALK')->sole(), '20000'))->further_tax_paise)->toBe(0);
    });

    it('issues a commercial invoice without tax when the mill has no STRN, and none on exempt goods', function () {
        app(TenantContext::class)->runAsSystem(fn () => Entity::query()->findOrFail($this->f->entity->id)->forceFill(['strn' => null])->save());
        $plain = taxInvoiceFor($this);
        expect($plain)->invoice_type->toBe('commercial')->sales_tax_paise->toBe(0)->further_tax_paise->toBe(0)->total_paise->toBe(40_200_000);

        app(TenantContext::class)->runAsSystem(fn () => Entity::query()->findOrFail($this->f->entity->id)->forceFill(['strn' => SalesFixtures::strn(1)])->save());
        $this->f->as($this->f->acc->owner);
        TaxRate::query()->whereKey($this->f->inv->rice->tax_rate_id)->sole()->forceFill(['exempt' => true])->save();
        $exempt = taxInvoiceFor($this, $this->f->saleSauda(walkInBuyer($this), '20000'));
        expect($exempt)->invoice_type->toBe('sales_tax')->tax_exempt->toBeTrue()->sales_tax_paise->toBe(0)->further_tax_paise->toBe(0);
    });

    it('lets the gate pass go once the invoice is posted (no e-invoice step)', function () {
        $bill = taxInvoiceFor($this);
        $this->f->as($this->f->dispatcher);
        app(GateService::class)->issueGatePass(GateEntry::query()->findOrFail($bill->gate_entry_id));
        expect(GateEvent::query()->where('event', 'gate_pass_issued')->exists())->toBeTrue();
    });
});

describe('tax registers', function () {
    it('lists sales and credit notes for Annex-C with totals net of credit notes', function () {
        $karachi = taxInvoiceFor($this);
        $multan = taxInvoiceFor($this, $this->f->saleSauda($this->f->multanBuyer));
        $walkIn = taxInvoiceFor($this, $this->f->saleSauda(walkInBuyer($this), '20000'));

        $this->f->as($this->f->dispatcher);
        $claim = app(ClaimService::class)->raise($karachi, 'weight_shortage', 'Short by 250 kg at the buyer', 1_055_250);
        $this->f->as($this->f->acc->owner);
        app(ClaimService::class)->approve($claim, 1_055_250);
        $this->f->as($this->f->acc->accountant);
        app(ClaimService::class)->settle($claim->fresh());

        $r = app(SalesTaxReturns::class)->salesRegister($this->period);
        expect(array_column($r['sales'], 'number'))->toHaveCount(4)->toContain($karachi->number, $multan->number, $walkIn->number)
            ->and(collect($r['sales'])->firstWhere('number', $walkIn->number))->toMatchArray(['buyer_type' => 'Unregistered', 'further_tax' => 1_608_000, 'pct' => '1006.3000'])
            ->and(collect($r['sales'])->firstWhere('type', 'CN'))->toMatchArray(['against' => $karachi->number, 'taxable' => -1_005_000, 'sales_tax' => -50_250])
            ->and($r['summary'])->toMatchArray([
                'taxable' => 3 * 40_200_000 - 1_005_000, 'sales_tax' => 3 * 2_010_000 - 50_250, 'further_tax' => 1_608_000, 'exempt' => 0,
            ])
            ->and($r['locked'])->toBeFalse();
    });
});

describe('withholding tax u/s 153', function () {
    beforeEach(function () {
        config(['ricemill.tax.wht_enabled' => true, 'ricemill.tax.wht_threshold_paise' => 30_000_000]); // Rs 300,000 for the test
    });

    function postPaddyBill(object $t)
    {
        $entry = $t->f->acc->receiveLot();
        $t->f->as($t->f->acc->accountant);

        return app(PurchaseBillService::class)->post($t->f->acc->billFor($entry));
    }

    it('withholds the non-filer rate on the part above the threshold and lists it in the register', function () {
        $bill = postPaddyBill($this); // value 36,805,312 paisa; 6,805,312 above Rs 300,000

        expect($bill)->wht_base_paise->toBe(6_805_312)->wht_rate_bp->toBe(1100)->wht_paise->toBe(748_584)->wht_filer->toBeFalse()
            ->net_paise->toBe(36_805_300 - 748_584);
        $line = VoucherLine::query()->where('voucher_id', $bill->voucher_id)->whereHas('account', fn ($q) => $q->where('code', 'WHT-153'))->sole();
        expect($line->cr_paise)->toBe(748_584)
            ->and(app(SalesTaxReturns::class)->withholdingRegister($this->period))->toHaveCount(1)
            ->and(app(SalesTaxReturns::class)->withholdingRegister($this->period)[0])->toMatchArray(['tax' => 748_584, 'rate_bp' => 1100, 'filer' => false]);
    });

    it('uses the filer rate for a supplier on the Active Taxpayer List and nothing when exempt or disabled', function () {
        $this->f->as($this->f->acc->owner);
        $this->f->inv->gate->seller->forceFill(['atl_status' => 'active'])->save();
        expect(postPaddyBill($this))->wht_rate_bp->toBe(550)->wht_paise->toBe(374_292)->wht_filer->toBeTrue();

        $this->f->as($this->f->acc->owner);
        $this->f->inv->gate->seller->forceFill(['wht_exempt' => true])->save();
        expect(postPaddyBill($this)->wht_paise)->toBe(0);

        config(['ricemill.tax.wht_enabled' => false]);
        $this->f->as($this->f->acc->owner);
        $this->f->inv->gate->seller->forceFill(['wht_exempt' => false])->save();
        expect(postPaddyBill($this)->wht_paise)->toBe(0);
    });
});

describe('printed documents', function () {
    it('prints the sales tax invoice with the letterhead, both parties\' tax numbers, words and a verification code', function () {
        $bill = taxInvoiceFor($this);
        $pdf = app(SalesDocuments::class)->invoicePdf($bill);
        $html = (string) PdfRenderer::$lastHtml;

        expect($pdf)->toStartWith('%PDF-')
            ->and($html)->toContain('SALES TAX INVOICE', 'سیلز ٹیکس انوائس', 'Al Mustafa Rice Mills', 'Adda Permat, 1 km Shujabad Road, Lodhran, Punjab', '0300-6834588')
            ->toContain('NTN 1234567-8', 'STRN '.SalesFixtures::strn(1), 'STRN '.SalesFixtures::strn(2), '1006.3000', '4,02,000.00', '20,100.00', '4,22,100.00')
            ->toContain('Rupees Four Hundred Twenty-Two Thousand One Hundred Only', 'images/brand/logo-wide-print.png', SalesDocuments::invoiceCode($bill));
        expect(app(DocumentVerifier::class)->verify((string) $bill->number, SalesDocuments::invoiceCode($bill)))->toMatchArray(['type' => 'Sales tax invoice']);
    });

    it('prints a plain invoice when the mill has no STRN', function () {
        app(TenantContext::class)->runAsSystem(fn () => Entity::query()->findOrFail($this->f->entity->id)->forceFill(['strn' => null])->save());
        app(SalesDocuments::class)->invoicePdf(taxInvoiceFor($this));

        expect((string) PdfRenderer::$lastHtml)->toContain('>INVOICE<', 'not registered for sales tax')->not->toContain('SALES TAX INVOICE');
    });

    it('prints the weighment slip with weights in kg and maund, the purchase bill and the payment voucher, and verifies them', function () {
        $entry = $this->f->acc->receiveLot();
        $this->f->as($this->f->acc->accountant);
        app(SlipRenderer::class)->pdf($entry);
        expect((string) PdfRenderer::$lastHtml)->toContain('WEIGHMENT SLIP', 'کانٹا پرچی', 'MNA-21-1234', 'maund', 'Payable weight');

        $bill = app(PurchaseBillService::class)->post($this->f->acc->billFor($entry));
        expect(app(AccountsDocuments::class)->purchaseBillPdf($bill))->toStartWith('%PDF-')
            ->and((string) PdfRenderer::$lastHtml)->toContain('PADDY PURCHASE BILL', (string) $bill->number, 'Net payable', '3,68,053.00', 'Rupees Three Hundred Sixty-Eight Thousand Fifty-Three Only');
        expect(app(DocumentVerifier::class)->verify((string) $bill->number, AccountsDocuments::purchaseBillCode($bill)))->toMatchArray(['type' => 'Purchase bill']);

        $payment = app(PaymentService::class)->request($this->f->inv->gate->seller, 'ibft', $this->f->acc->bank, 36_805_300, now(), [$bill->id => 36_805_300]);
        app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($payment->approval_request_id), $this->f->acc->owner);
        $payment->refresh();
        expect(app(AccountsDocuments::class)->paymentVoucherPdf($payment))->toStartWith('%PDF-')
            ->and((string) PdfRenderer::$lastHtml)->toContain('PAYMENT VOUCHER', 'ادائیگی واؤچر', 'IBFT (online transfer)', (string) $bill->number, 'IBAN ending')
            ->and(app(DocumentVerifier::class)->verify($payment->number, AccountsDocuments::paymentCode($payment)))->toMatchArray(['type' => 'Payment voucher'])
            ->and(app(DocumentVerifier::class)->verify($payment->number, 'AAAA-BBBB-CCCC'))->toBeNull();
    });
});
