<?php

declare(strict_types=1);

use App\Domain\Gate\GateService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Production\ProductionService;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Godown;
use App\Models\Production\ProductionLog;
use Illuminate\Support\Str;
use Tests\Support\GateFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new InventoryFixtures(Make::entity('RCV'));
    $this->sauda = $this->f->gate->confirmedSauda('100000');
    $this->f->as($this->f->supervisor);
    $this->gate = app(GateService::class);
});

function receiveLot(object $t, ?Godown $godown): GateEntry
{
    $g = app(GateService::class); // fresh: an API call in between replaces the scoped contexts
    $e = $g->arrive(['registration_no' => 'HR05AB1234', 'sauda_id' => $t->sauda->id, 'bag_count' => 400]);
    $g->recordWeighment($e, 'gross', '25480', $t->f->gate->scale, GateFixtures::readings('25480'), str_repeat('a', 64));
    $g->recordLabTest($e, ['moisture_bp' => 1850]);
    $g->decideLab($e, true);
    $g->markUnloaded($e, godown: $godown);
    $g->recordWeighment($e, 'tare', '10230', $t->f->gate->scale, GateFixtures::readings('10230'), str_repeat('a', 64));
    $g->issueSlip($e);

    return $e->fresh();
}

it('books the physical net weight into the godown chosen at unloading', function () {
    $e = receiveLot($this, $this->f->silo);
    $receipt = StockEntry::query()->where('source_type', 'gate_entry')->where('source_id', $e->id)->sole();

    // Payable is lower (quality deductions) but the godown holds the full net weight.
    expect($e->godown_id)->toBe($this->f->silo->id)
        ->and(bccomp($e->payable_kg, $e->net_kg, 3))->toBe(-1)
        ->and($receipt->quantity_kg)->toBe('15250.000')
        ->and($receipt->movement)->toBe('receipt')
        ->and(app(StockLedger::class)->balance($this->f->paddy, $this->f->silo)->kg)->toBe('15250.000')
        ->and($e->flags)->not->toContain('no_godown');
});

it('flags a slip without a godown instead of guessing where the paddy went', function () {
    $e = receiveLot($this, null);

    expect($e->flags)->toContain('no_godown')
        ->and(StockEntry::query()->count())->toBe(0);
});

it('accepts hourly production logs from the plant tablet, with retry while stock is missing', function () {
    $batch = app(ProductionService::class)->open($this->f->paddy, openedAt: now()->subHours(3));
    $headers = Make::apiHeaders(Make::apiTokens($this->f->supervisor, 'plant-tablet-0001-abcd'), 'plant-tablet-0001-abcd');
    $seq = 0;
    $push = function (string $id, array $payload) use ($headers, &$seq): array {
        return $this->withHeaders($headers)->postJson('/api/v1/sync/push', ['events' => [[
            'event_id' => (string) Str::uuid(), 'client_seq' => ++$seq, 'occurred_at' => now()->toIso8601ZuluString('millisecond'),
            'type' => 'production_log.recorded', 'record_id' => $id, 'payload' => $payload,
        ]]])->assertOk()->json('results.0');
    };
    $period = ['period_start' => now()->subHours(2)->toIso8601ZuluString(), 'period_end' => now()->subHour()->toIso8601ZuluString()];

    $inputId = (string) Str::uuid();
    $input = ['batch_id' => $batch->id, 'kind' => 'input', 'godown_id' => $this->f->silo->id, 'quantity_kg' => '3000'] + $period;
    expect($push($inputId, $input))->toMatchArray(['status' => 'retry', 'reason' => 'insufficient_stock']);

    $this->f->as($this->f->supervisor);
    receiveLot($this, $this->f->silo); // the gate's receipt arrives
    expect($push($inputId, $input)['status'])->toBe('applied');
    expect($push($inputId, $input))->toMatchArray(['status' => 'applied']);  // idempotent resend

    $out = $push((string) Str::uuid(), ['batch_id' => $batch->id, 'kind' => 'output', 'product_id' => $this->f->rice->id, 'godown_id' => $this->f->store->id, 'quantity_kg' => '1950.5'] + $period);
    expect($out['status'])->toBe('applied');
    expect($push((string) Str::uuid(), ['batch_id' => $batch->id, 'kind' => 'output', 'godown_id' => $this->f->store->id, 'quantity_kg' => '1'] + $period)['status'])->toBe('rejected');

    $this->f->as($this->f->supervisor);
    expect(ProductionLog::query()->count())->toBe(2)
        ->and($batch->fresh()->input_kg)->toBe('3000.000')
        ->and($batch->fresh()->output_kg)->toBe('1950.500');
});

it('lets the plant tablet pull open batches', function () {
    $batch = app(ProductionService::class)->open($this->f->paddy, 'Line A');
    $headers = Make::apiHeaders(Make::apiTokens($this->f->supervisor, 'plant-tablet-0002-abcd'), 'plant-tablet-0002-abcd');

    $changes = collect($this->withHeaders($headers)->getJson('/api/v1/sync/pull')->assertOk()->json('changes'));
    $pulled = $changes->firstWhere('type', 'production_batch')['data'];

    expect($pulled)->toMatchArray(['id' => $batch->id, 'number' => $batch->number, 'status' => 'open', 'line' => 'Line A']);
});
