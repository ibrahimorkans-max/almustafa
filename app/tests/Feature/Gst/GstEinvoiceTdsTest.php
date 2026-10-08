<?php

declare(strict_types=1);

use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Claims\ClaimService;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Gate\GateService;
use App\Domain\Gst\EInvoiceService;
use App\Domain\Gst\GstReturns;
use App\Domain\Gst\SandboxEInvoiceProvider;
use App\Domain\Sales\SalesBillService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\VoucherLine;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gst\EInvoiceEvent;
use App\Models\Masters\Party;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('GST'));
    app(TenantContext::class)->runAsSystem(fn () => Entity::query()->whereKey($this->f->entity->id)->first()
        ->forceFill(['gstin' => SalesFixtures::gstin('06', 'AAACG1234', 'C'), 'city' => 'Karnal', 'pincode' => '132001', 'legal_name' => 'GST Rice Mill Pvt Ltd'])->save());
    $this->period = now('Asia/Kolkata')->format('Y-m');
    $this->f->as($this->f->inv->storeManager);
    $this->f->inv->stock($this->f->inv->rice, $this->f->inv->store, '40000'); // room for several loads
});

function invoiceFor(object $t, $sauda = null)
{
    $entry = $t->f->loadAndWeigh($t->f->order($sauda));
    $t->f->as($t->f->acc->accountant);

    return app(SalesBillService::class)->post($t->f->billFor($entry));
}

describe('GST returns', function () {
    it('builds GSTR-1 tables, the HSN summary and 3B totals from posted documents', function () {
        $delhi = invoiceFor($this);                                            // B2B inter-state, IGST 20,100
        $local = invoiceFor($this, $this->f->saleSauda($this->f->localBuyer)); // B2B intra-state, CGST + SGST
        $this->f->as($this->f->acc->owner);
        $walkIn = Party::query()->create(['code' => 'C-CASH', 'name' => 'Walk-in buyer', 'is_customer' => true, 'state_code' => '06', 'gst_registration_type' => 'consumer']);
        $b2c = invoiceFor($this, $this->f->saleSauda($walkIn, '20000'));

        $this->f->as($this->f->dispatcher);
        $claim = app(ClaimService::class)->raise($delhi, 'weight_shortage', 'Short by 250 kg at the buyer', 1_055_250);
        $this->f->as($this->f->acc->owner);
        app(ClaimService::class)->approve($claim, 1_055_250);
        $this->f->as($this->f->acc->accountant);
        app(ClaimService::class)->settle($claim->fresh());

        $r1 = app(GstReturns::class)->gstr1($this->period);
        expect(array_column($r1['b2b'], 'number'))->toBe([$delhi->number, $local->number])
            ->and($r1['b2b'][0])->toMatchArray(['ctin' => $delhi->buyer_gstin, 'pos' => '07', 'taxable' => 40_200_000, 'igst' => 2_010_000, 'cgst' => 0, 'rate_bp' => 500])
            ->and($r1['b2b'][1])->toMatchArray(['pos' => '06', 'cgst' => 1_005_000, 'sgst' => 1_005_000, 'igst' => 0])
            ->and($r1['b2cs'])->toHaveCount(1)
            ->and($r1['b2cs'][0])->toMatchArray(['supply' => 'INTRA', 'pos' => '06', 'taxable' => $b2c->taxable_paise])
            ->and($r1['cdnr'])->toHaveCount(1)
            ->and($r1['cdnr'][0])->toMatchArray(['original_invoice' => $delhi->number, 'taxable' => 1_005_000, 'igst' => 50_250])
            ->and($r1['hsn'])->toHaveCount(1)
            ->and($r1['hsn'][0])->toMatchArray(['hsn' => '10063020', 'quantity_kg' => '30150.000', 'taxable' => 120_600_000])
            ->and($r1['locked'])->toBeFalse();

        $r3b = app(GstReturns::class)->gstr3b($this->period);
        expect($r3b['outward_taxable'])->toBe([
            'taxable' => 120_600_000 - 1_005_000, 'igst' => 2_010_000 - 50_250, 'cgst' => 1_005_000 + 1_005_000, 'sgst' => 1_005_000 + 1_005_000,
        ]);

        $json = app(GstReturns::class)->gstr1Json($this->period);
        expect($json['fp'])->toBe(now('Asia/Kolkata')->format('mY'))
            ->and($json['b2b'])->toHaveCount(2)
            ->and($json['b2b'][0]['inv'][0])->toMatchArray(['inum' => $delhi->number, 'val' => 422100.0, 'pos' => '07'])
            ->and($json['b2b'][0]['inv'][0]['itms'][0]['itm_det'])->toMatchArray(['txval' => 402000.0, 'rt' => 5.0, 'iamt' => 20100.0])
            ->and($json['cdnr'][0]['nt'][0])->toMatchArray(['ntty' => 'C', 'val' => 10552.5])
            ->and($json['hsn']['data'][0])->toMatchArray(['hsn_sc' => '10063020', 'uqc' => 'KGS', 'qty' => 30150.0]);
    });
});

describe('e-invoice and e-way bill', function () {
    beforeEach(fn () => config(['ricemill.gst.einvoice_enabled' => true]));

    it('generates the IRN the way the IRP computes it, keeps every exchange and is idempotent', function () {
        $bill = invoiceFor($this);
        $einvoice = app(EInvoiceService::class);
        $bill = $einvoice->generate($bill);

        $expected = SandboxEInvoiceProvider::irn(SalesFixtures::gstin('06', 'AAACG1234', 'C'), DocumentNumbers::financialYear(now()), 'INV', (string) $bill->number);
        expect($bill->irn)->toBe($expected)->and($bill->einvoice_status)->toBe('generated')->and($bill->irn_signed_qr)->toStartWith('SANDBOX.')
            ->and(EInvoiceEvent::query()->sole()->request['ValDtls'])->toMatchArray(['AssVal' => 402000.0, 'IgstVal' => 20100.0, 'TotInvVal' => 422100.0]);

        $einvoice->generate($bill);
        expect(EInvoiceEvent::query()->count())->toBe(1);
        expect(fn () => DB::table('einvoice_events')->delete())->toThrow(QueryException::class);
    });

    it('needs the IRN before the gate pass, then the e-way bill, and limits cancellation', function () {
        $bill = invoiceFor($this);
        $entry = GateEntry::query()->findOrFail($bill->gate_entry_id);
        $this->f->as($this->f->dispatcher);
        expect(fn () => app(GateService::class)->issueGatePass($entry))->toThrow(BusinessRuleViolation::class, 'IRN');

        $this->f->as($this->f->acc->accountant);
        $einvoice = app(EInvoiceService::class);
        $bill = $einvoice->generate($bill);
        config(['ricemill.gst.require_eway_for_gate_pass' => true]);
        $this->f->as($this->f->dispatcher);
        expect(fn () => app(GateService::class)->issueGatePass($entry))->toThrow(BusinessRuleViolation::class, 'e-way bill');

        $this->f->as($this->f->acc->accountant);
        $bill = $einvoice->ewayBill($bill, 250);
        expect($bill->eway_bill_no)->toHaveLength(12)
            ->and($bill->fresh()->eway_valid_until->timezone('Asia/Kolkata')->format('H:i'))->toBe('23:59') // stored in UTC, valid to IST midnight
            ->and(EInvoiceEvent::query()->where('kind', 'ewb_generated')->sole()->request)->toMatchArray(['VehNo' => 'HR05AB1234', 'Distance' => 250]);
        expect(fn () => $einvoice->cancel($bill, '2', 'Wrong rate'))->toThrow(BusinessRuleViolation::class, 'e-way bill');

        $this->f->as($this->f->dispatcher);
        app(GateService::class)->issueGatePass($entry);
        expect(GateEvent::query()->where('event', 'gate_pass_issued')->exists())->toBeTrue();
    });

    it('cancels an IRN only within 24 hours and refuses B2C or incomplete seller details', function () {
        $bill = app(EInvoiceService::class)->generate(invoiceFor($this));
        $this->travel(25)->hours();
        expect(fn () => app(EInvoiceService::class)->cancel($bill, '2', 'Wrong rate'))->toThrow(BusinessRuleViolation::class, '24 hours');
        $this->travelBack();
        app(EInvoiceService::class)->cancel($bill, '2', 'Data entry mistake in rate');
        expect($bill->fresh()->einvoice_status)->toBe('cancelled');

        $this->f->as($this->f->acc->owner);
        $walkIn = Party::query()->create(['code' => 'C-CASH', 'name' => 'Walk-in buyer', 'is_customer' => true, 'state_code' => '06', 'gst_registration_type' => 'consumer']);
        $b2c = invoiceFor($this, $this->f->saleSauda($walkIn, '20000'));
        expect(fn () => app(EInvoiceService::class)->generate($b2c))->toThrow(BusinessRuleViolation::class, 'B2B');

        app(TenantContext::class)->runAsSystem(fn () => Entity::query()->whereKey($this->f->entity->id)->update(['pincode' => null]));
        $local = invoiceFor($this, $this->f->saleSauda($this->f->localBuyer)); // the Delhi buyer is at its credit limit by now
        expect(fn () => app(EInvoiceService::class)->generate($local))->toThrow(BusinessRuleViolation::class, 'PIN code');
    });
});

describe('TDS u/s 194Q', function () {
    beforeEach(function () {
        config(['ricemill.gst.tds_194q_enabled' => true, 'ricemill.gst.tds_194q_threshold_paise' => 30_000_000]); // ₹3 lakh for the test
    });

    function postPurchase(object $t)
    {
        $entry = $t->f->acc->receiveLot();
        $t->f->as($t->f->acc->accountant);

        return app(PurchaseBillService::class)->post($t->f->acc->billFor($entry));
    }

    it('withholds 5 % on the part above the threshold from a seller without PAN', function () {
        $bill = postPurchase($this); // value 36,805,312 paise; 6,805,312 above ₹3 lakh

        expect($bill)->tds_base_paise->toBe(6_805_312)->tds_rate_bp->toBe(500)->tds_paise->toBe(340_266)
            ->net_paise->toBe(36_805_300 - 340_266);
        $tdsLine = VoucherLine::query()->where('voucher_id', $bill->voucher_id)->whereHas('account', fn ($q) => $q->where('code', 'TDS-194Q'))->sole();
        expect($tdsLine->cr_paise)->toBe(340_266);
    });

    it('uses 0.1 % with a PAN and nothing for an exempt seller or below the threshold', function () {
        $this->f->as($this->f->acc->owner);
        $this->f->inv->gate->seller->forceFill(['pan' => 'ABCPS1234K'])->save();
        expect(postPurchase($this))->tds_rate_bp->toBe(10)->tds_paise->toBe(6_805);

        $this->f->as($this->f->acc->owner);
        $this->f->inv->gate->seller->forceFill(['tds_194q_exempt' => true])->save();
        expect(postPurchase($this)->tds_paise)->toBe(0);

        config(['ricemill.gst.tds_194q_enabled' => false]);
        $this->f->as($this->f->acc->owner);
        $this->f->inv->gate->seller->forceFill(['tds_194q_exempt' => false])->save();
        expect(postPurchase($this)->tds_paise)->toBe(0);
    });
});
