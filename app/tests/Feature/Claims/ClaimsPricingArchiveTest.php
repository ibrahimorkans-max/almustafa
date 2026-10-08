<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountReports;
use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\PaymentService;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Approvals\Actions\VoucherReversal;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Archive\ArchiveService;
use App\Domain\Claims\ClaimService;
use App\Domain\Gate\GateService;
use App\Domain\Pricing\PricingService;
use App\Domain\Sales\SalesBillService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\ProductCost;
use App\Models\Accounts\VoucherLine;
use App\Models\AuditLog;
use App\Models\Claims\Claim;
use App\Models\Claims\ClaimEvent;
use App\Models\Gate\GateEntry;
use App\Models\Pricing\PriceListItem;
use App\Models\StoredFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('CLM'));
    $this->claims = fn () => app(ClaimService::class);
});

function postedInvoice(object $t, $sauda = null)
{
    $entry = $t->f->loadAndWeigh($t->f->order($sauda));
    $t->f->as($t->f->acc->accountant);

    return app(SalesBillService::class)->post($t->f->billFor($entry));
}

function evidence(): string
{
    return StoredFile::query()->create([
        'sha256' => hash('sha256', 'photo-of-wet-bags'), 'mime' => 'image/jpeg', 'size_bytes' => 17, 'path' => 'x',
    ])->sha256;
}

describe('claims', function () {
    it('settles a buyer claim by a credit note that reverses IGST in the invoice proportion', function () {
        $invoice = postedInvoice($this);
        $this->f->as($this->f->dispatcher);
        $claim = ($this->claims)()->raise($invoice, 'weight_shortage', 'Buyer weighed 250 kg less at their godown', 1_200_000);

        expect($claim->number)->toContain('/CLM/')->and($claim->status)->toBe('open');
        expect(fn () => ($this->claims)()->approve($claim, 1_055_250))->toThrow(BusinessRuleViolation::class); // raiser lacks claim.approve

        $this->f->as($this->f->acc->owner);
        expect(fn () => ($this->claims)()->approve($claim, 1_200_001))->toThrow(BusinessRuleViolation::class, 'not more than');
        ($this->claims)()->approve($claim, 1_055_250, 'Agreed 250 kg at invoice rate incl. GST');

        $this->f->as($this->f->acc->accountant);
        $settled = ($this->claims)()->settle($claim->fresh());
        $lines = VoucherLine::query()->where('voucher_id', $settled->voucher_id)->with('account')->orderBy('line_no')->get()
            ->map(fn ($l) => [$l->account->code, $l->dr_paise, $l->cr_paise])->all();

        expect($settled->status)->toBe('settled')
            ->and($lines)->toBe([['SAL-CLAIMS', 1_005_000, 0], ['GST-OUT-IGST', 50_250, 0], ['P-C-DEL', 0, 1_055_250]])
            ->and(app(SalesBillService::class)->outstanding($invoice->fresh()))->toBe(42_210_000 - 1_055_250)
            ->and(app(AccountReports::class)->receivablesAgeing(now())[0]['outstanding'])->toBe(42_210_000 - 1_055_250)
            ->and(ClaimEvent::query()->where('claim_id', $claim->id)->pluck('event')->all())->toBe(['raised', 'approved', 'settled']);
    });

    it('splits CGST and SGST for a buyer in the same state', function () {
        $invoice = postedInvoice($this, $this->f->saleSauda($this->f->localBuyer));
        $split = ClaimService::split(1_055_250, $invoice);

        expect($split)->toBe(['taxable' => 1_005_000, 'cgst' => 25_125, 'sgst' => 25_125, 'igst' => 0])
            ->and(array_sum($split))->toBe(1_055_250);
    });

    it('settles a claim on a supplier by a debit note that lowers what the mill pays', function () {
        $entry = $this->f->acc->receiveLot();
        $this->f->as($this->f->acc->accountant);
        $bill = app(PurchaseBillService::class)->post($this->f->acc->billFor($entry));

        $this->f->as($this->f->inv->supervisor);
        $claim = ($this->claims)()->raise($bill, 'quality', 'Moisture found at 21 % after unloading, lot heated', 500_000, [evidence()]);
        $this->f->as($this->f->acc->owner);
        ($this->claims)()->approve($claim, 400_000);
        $this->f->as($this->f->acc->accountant);
        ($this->claims)()->settle($claim->fresh());

        expect(app(PurchaseBillService::class)->outstanding($bill->fresh()))->toBe(36_805_300 - 400_000)
            ->and(app(Books::class)->balance(app(ChartOfAccounts::class)->system('purchase_claims')))->toBe(-400_000);
        expect(fn () => app(PaymentService::class)->request($this->f->inv->gate->seller, 'neft', $this->f->acc->bank, 36_805_300, now(), [$bill->id => 36_805_300]))
            ->toThrow(BusinessRuleViolation::class, 'outstanding');
    });

    it('keeps the decider different from the raiser and the history append-only', function () {
        $invoice = postedInvoice($this);
        $this->f->as($this->f->acc->owner);
        $claim = ($this->claims)()->raise($invoice, 'damage', 'Twenty bags torn and wet on arrival', 100_000);

        expect(fn () => ($this->claims)()->approve($claim, 100_000))->toThrow(BusinessRuleViolation::class, 'cannot decide');
        $this->f->as($this->f->acc->owner2);
        ($this->claims)()->reject($claim, 'Damage happened after delivery');
        expect($claim->fresh()->status)->toBe('rejected');

        expect(fn () => ($this->claims)()->raise($invoice, 'other', 'Rate dispute beyond the invoice', 42_210_001))
            ->toThrow(BusinessRuleViolation::class, 'can still be claimed');
        expect(fn () => ($this->claims)()->raise($invoice, 'other', 'Photo evidence that was never uploaded', 100, [str_repeat('f', 64)]))
            ->toThrow(BusinessRuleViolation::class, 'uploaded');
        expect(fn () => DB::table('claim_events')->update(['event' => 'approved']))->toThrow(QueryException::class);
    });

    it('puts a claim back to approved when its credit note is reversed, and blocks reversing the invoice meanwhile', function () {
        $invoice = postedInvoice($this);
        $this->f->as($this->f->dispatcher);
        $claim = ($this->claims)()->raise($invoice, 'weight_shortage', 'Short by 100 kg at the buyer', 400_000);
        $this->f->as($this->f->acc->owner);
        ($this->claims)()->approve($claim, 400_000);
        $this->f->as($this->f->acc->accountant);
        $claim = ($this->claims)()->settle($claim->fresh());

        $approvals = app(ApprovalService::class);
        $reverseInvoice = $approvals->submit(VoucherReversal::KEY, ['voucher_id' => $invoice->voucher_id, 'reason' => 'Wrong buyer on the invoice'], 'x');
        expect($approvals->approve($reverseInvoice, $this->f->acc->owner)->error)->toContain('Claims are approved or settled');

        $this->f->as($this->f->acc->accountant);
        $reverseNote = $approvals->submit(VoucherReversal::KEY, ['voucher_id' => $claim->voucher_id, 'reason' => 'Note posted on the wrong date'], 'x');
        $approvals->approve($reverseNote, $this->f->acc->owner);

        expect($claim->fresh()->status)->toBe('approved')->and($claim->fresh()->voucher_id)->toBeNull()
            ->and(app(SalesBillService::class)->outstanding($invoice->fresh()))->toBe(42_210_000);
    });
});

describe('pricing', function () {
    it('changes list rates only through a second person and keeps every version', function () {
        $this->f->as($this->f->acc->owner);
        $pricing = app(PricingService::class);
        $list = $pricing->createList('Rice rates Oct 2026', now()->startOfMonth());
        $request = $pricing->requestRate($list, $this->f->inv->rice, 420000, 'Market up after Diwali');

        expect(app(ApprovalService::class)->canDecide($this->f->acc->owner, $request))->toBeFalse();
        app(ApprovalService::class)->approve($request, $this->f->acc->owner2);
        app(ApprovalService::class)->approve($pricing->requestRate($list, $this->f->inv->rice, 425000, 'Further rise'), $this->f->acc->owner2);

        $items = PriceListItem::query()->orderBy('created_at')->orderBy('id')->get();
        expect($pricing->listRate($this->f->inv->rice))->toBe(425000)
            ->and($items)->toHaveCount(2)
            ->and($items[1]->supersedes_id)->toBe($items[0]->id);
        expect(fn () => DB::table('price_list_items')->update(['rate_paise_per_quintal' => 1]))->toThrow(QueryException::class);
    });

    it('shows cost and margin only to those allowed, with the real figures', function () {
        Make::system(fn () => ProductCost::query()->create([
            'entity_id' => $this->f->entity->id, 'product_id' => $this->f->inv->rice->id, 'avg_paise_per_kg' => '3000.0000',
            'basis_kg' => '20000', 'source_type' => 'production_batch', 'created_at' => now(),
        ]));
        $this->f->as($this->f->acc->owner);
        $pricing = app(PricingService::class);
        $pricing->setMarkup('rice', 2500);

        expect($pricing->margin($this->f->dispatcher, $this->f->inv->rice, 420000))->toBeNull()
            ->and($pricing->margin($this->f->acc->accountant, $this->f->inv->rice, 420000))->toBeNull()
            ->and($pricing->margin($this->f->acc->owner, $this->f->inv->rice, 420000))->toBe([
                'cost_paise_per_quintal' => 300000, 'margin_paise_per_quintal' => 120000, 'margin_bp' => 2857, 'suggested_paise_per_quintal' => 375000,
            ]);

        $this->f->as($this->f->dispatcher);
        expect(fn () => app(PricingService::class)->setMarkup('rice', 5000))->toThrow(BusinessRuleViolation::class, 'pricing.markup.set');
    });
});

describe('archive', function () {
    beforeEach(function () {
        $this->f->as($this->f->dispatcher);
        $gate = app(GateService::class);
        $this->old = $gate->arrive(['registration_no' => 'HR05AB1234']);
        $gate->cancel($this->old, 'Driver left');
        $this->recent = $gate->arrive(['registration_no' => 'HR05AB1234']);
        $gate->cancel($this->recent, 'Driver left');
        DB::table('gate_entries')->where('id', $this->old->id)->update(['updated_at' => now()->subMonths(30)]);
    });

    it('archives only closed records past retention, never deletes, and restores with a reason', function () {
        $archive = app(ArchiveService::class);
        $cutoff = now()->subMonths(24);
        $this->f->as($this->f->acc->owner);

        expect($archive->run($cutoff, dryRun: true)['gate_entry'])->toBe(1)
            ->and(GateEntry::query()->whereNotNull('archived_at')->count())->toBe(0);

        Make::reset();
        Artisan::call('archive:run');
        $this->f->as($this->f->acc->owner);
        expect(GateEntry::query()->find($this->old->id)->archived_at)->not->toBeNull()
            ->and(GateEntry::query()->find($this->recent->id)->archived_at)->toBeNull()
            ->and(GateEntry::query()->count())->toBe(2)
            ->and(Make::system(fn () => AuditLog::query()->where('action', 'archive.run')->exists()))->toBeTrue(); // global chain: the run spans all mills

        $found = $archive->search('hr05ab');
        expect($found)->toHaveCount(1)->and($found[0])->toMatchArray(['type' => 'gate_entry', 'id' => $this->old->id]);

        expect(fn () => $archive->restore('gate_entry', $this->old->id, 'oops'))->toThrow(BusinessRuleViolation::class, 'reason');
        $archive->restore('gate_entry', $this->old->id, 'Needed for a weight dispute in court');
        expect(GateEntry::query()->find($this->old->id)->archived_at)->toBeNull()
            ->and(AuditLog::query()->where('action', 'archive.restored')->exists())->toBeTrue();
    });

    it('lets only the right roles search and restore', function () {
        $this->f->as($this->f->dispatcher);
        expect(fn () => app(ArchiveService::class)->search('HR05'))->toThrow(BusinessRuleViolation::class, 'archive.search');
        $this->f->as($this->f->acc->accountant); // can search, cannot restore
        expect(app(ArchiveService::class)->search('HR05'))->toBe([]);
        expect(fn () => app(ArchiveService::class)->restore('gate_entry', $this->old->id, 'A good long reason here'))->toThrow(BusinessRuleViolation::class, 'archive.restore');
    });
});
