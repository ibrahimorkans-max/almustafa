<?php

declare(strict_types=1);

use App\Domain\Alerts\AnomalyScanner;
use App\Domain\Gate\GateService;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Reports\OperationalReports;
use App\Domain\Sales\SalesBillService;
use App\Domain\Sales\SalesDocuments;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Procurement\Sauda;
use App\Models\Production\ProductionBatch;
use Carbon\CarbonImmutable;
use Tests\Support\GateFixtures;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    // Pakistan dates against now(): at midday both clocks give the same day, whenever the tests are started.
    $this->travelTo(now('Asia/Karachi')->setTime(12, 0));
    $this->f = new SalesFixtures(Make::entity('RAV'));
});

/** A past trip of the test vehicle with the given net weight (history for the outlier rule). */
function pastTrip(object $t, string $netKg, int $daysAgo): void
{
    $t->f->as($t->f->acc->owner);
    $e = new GateEntry(['direction' => 'inbound', 'purpose' => 'paddy_purchase', 'registration_no' => 'MNA211234', 'arrived_at' => now()->subDays($daysAgo)]);
    $e->forceFill(['number' => 'H-'.$daysAgo, 'status' => 'exited', 'net_kg' => $netKg, 'flags' => []])->save();
}

describe('anomaly alerts', function () {
    it('flags a load far from the vehicle\'s usual weight, once', function () {
        foreach ([15000, 15200, 14900, 15100, 15050, 14950] as $i => $kg) {
            pastTrip($this, (string) $kg, 30 - $i);
        }
        $this->f->acc->receiveLot(); // net 15,250 kg — normal
        $this->f->as($this->f->acc->owner);
        expect(app(AnomalyScanner::class)->scan(now()->subDay())['weight_outlier'])->toBe(0);

        // A truck that suddenly carries 2,000 kg more than ever.
        $this->f->as($this->f->inv->supervisor);
        $g = app(GateService::class);
        $e = $g->arrive(['registration_no' => 'MNA211234', 'sauda_id' => $this->f->acc->sauda->id, 'bag_count' => 400]);
        $g->recordLabTest($e, ['moisture_bp' => 1600]);
        $g->decideLab($e, true);
        $g->recordWeighment($e, 'gross', '27480', $this->f->inv->gate->scale, GateFixtures::readings('27480'), str_repeat('a', 64));
        $g->markUnloaded($e, godown: $this->f->inv->silo);
        $g->recordWeighment($e, 'tare', '10230', $this->f->inv->gate->scale, GateFixtures::readings('10230'), str_repeat('a', 64));
        $this->f->inv->gate->settle($g, $e);
        $g->issueSlip($e);

        $this->f->as($this->f->acc->owner);
        $first = app(AnomalyScanner::class)->scan(now()->subDay());
        $again = app(AnomalyScanner::class)->scan(now()->subDay());
        $alert = Alert::query()->where('type', 'weight_outlier')->sole();

        expect($first['weight_outlier'])->toBe(1)->and($again['weight_outlier'])->toBe(0)
            ->and($alert->title)->toContain('17250')
            ->and($alert->body)->toContain('z-score')
            ->and($alert->data)->toMatchArray(['net_kg' => '17250.000', 'trips' => 7]);
    });

    it('flags a purchase rate far from the 30-day median and weighments at night', function () {
        $this->f->as($this->f->acc->owner);
        foreach ([100000, 100800, 99200] as $i => $rate) {
            $s = new Sauda(['direction' => 'purchase', 'party_id' => $this->f->inv->gate->seller->id, 'product_id' => $this->f->inv->paddy->id,
                'quantity_kg' => '10000', 'rate_paise_per_maund' => $rate, 'delivery_from' => now()->toDateString(), 'delivery_to' => now()->toDateString()]);
            $s->forceFill(['number' => "P-OLD-{$i}", 'status' => 'completed', 'approved_at' => now()->subDays(10 + $i)])->save();
        }
        $high = new Sauda(['direction' => 'purchase', 'party_id' => $this->f->inv->gate->seller->id, 'product_id' => $this->f->inv->paddy->id,
            'quantity_kg' => '10000', 'rate_paise_per_maund' => 120000, 'delivery_from' => now()->toDateString(), 'delivery_to' => now()->toDateString()]);
        $high->forceFill(['number' => 'P-HIGH', 'status' => 'confirmed', 'approved_at' => now()->subHour()])->save();

        $this->f->as($this->f->inv->supervisor);
        $entry = app(GateService::class)->arrive(['registration_no' => 'MNA211234']);
        $night = CarbonImmutable::now('Asia/Karachi')->startOfDay()->addHours(2);
        app(GateService::class)->recordWeighment($entry, 'gross', '20000', $this->f->inv->gate->scale, GateFixtures::readings('20000'), str_repeat('b', 64), $night->utc(), strict: false);

        $this->f->as($this->f->acc->owner);
        $counts = app(AnomalyScanner::class)->scan($night->subHour(), now()->addHour());
        expect($counts['rate_outlier'])->toBe(1)->and($counts['after_hours'])->toBe(1)
            ->and(Alert::query()->where('type', 'rate_outlier')->sole()->title)->toContain('P-HIGH');
    });

    it('flags an outturn that stays outside the standard batch after batch', function () {
        $this->f->as($this->f->inv->supervisor);
        foreach (range(1, 3) as $i) {
            $b = new ProductionBatch(['number' => "B-{$i}", 'paddy_product_id' => $this->f->inv->paddy->id, 'opened_at' => now()->subDays(5 - $i)]);
            $b->forceFill(['status' => 'closed', 'closed_at' => now()->subDays(4 - $i), 'yield_snapshot' => ['outturn' => [
                $this->f->inv->rice->id => ['product' => 'Rice 1121 (head)', 'bp' => 6000, 'expected_bp' => 6500, 'out_of_range' => true],
            ]]])->save();
        }
        expect(app(AnomalyScanner::class)->scan(now()->subDay())['yield_drift'])->toBe(1)
            ->and(Alert::query()->where('type', 'yield_drift')->sole()->title)->toContain('last 3 batches');
    });
});

describe('reports and verification', function () {
    it('lists registers from the records and audits CSV exports, defusing formulas', function () {
        $entry = $this->f->loadAndWeigh($this->f->order());
        $this->f->as($this->f->acc->accountant);
        $bill = app(SalesBillService::class)->post($this->f->billFor($entry));
        $this->f->karachiBuyer->forceFill(['name' => '=HYPERLINK("http://evil")'])->save();

        [$cols, $rows] = app(OperationalReports::class)->run('sales_register', now()->subDay(), now());
        expect($cols[1])->toBe('Invoice')->and($rows)->toHaveCount(1)->and($rows[0][1])->toBe($bill->number)->and($rows[0][12])->toBe('422100.00');

        $csv = app(OperationalReports::class)->csv('sales_register', now()->subDay(), now());
        expect($csv)->toContain("'=HYPERLINK")
            ->and(AuditLog::query()->where('action', 'report.exported')->exists())->toBeTrue();
    });

    it('confirms genuine printed documents publicly and rejects altered ones', function () {
        $entry = $this->f->loadAndWeigh($this->f->order());
        $this->f->as($this->f->acc->accountant);
        $bill = app(SalesBillService::class)->post($this->f->billFor($entry));
        $this->f->as($this->f->dispatcher);
        app(GateService::class)->issueGatePass($entry);
        $slip = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'slip_issued')->sole();
        $pass = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'gate_pass_issued')->sole();
        $invoiceCode = SalesDocuments::invoiceCode($bill);
        Make::reset();
        app('auth')->forgetGuards();

        $this->get('/verify?'.http_build_query(['doc' => $bill->number, 'code' => $invoiceCode]))
            ->assertOk()->assertSee('Genuine sales tax invoice')->assertSee('4,22,100.00')->assertDontSee($this->f->karachiBuyer->name);
        $this->get('/verify?'.http_build_query(['doc' => $entry->number, 'code' => SlipRenderer::verificationCode($entry->id, $slip->data)]))
            ->assertOk()->assertSee('Genuine weighment slip')->assertSee('10050.000');
        $this->get('/verify?'.http_build_query(['doc' => $pass->data['number'], 'code' => SlipRenderer::verificationCode($entry->id, $pass->data)]))
            ->assertOk()->assertSee('Genuine gate pass');
        $this->get('/verify?'.http_build_query(['doc' => $bill->number, 'code' => 'AAAA-BBBB-CCCC']))
            ->assertOk()->assertSee('Not verified');
    });
});
