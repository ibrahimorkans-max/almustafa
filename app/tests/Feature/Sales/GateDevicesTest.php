<?php

declare(strict_types=1);

use App\Domain\Gate\GateDevices;
use App\Domain\Gate\GateService;
use App\Domain\Sales\SalesBillService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Gate\GateDeviceEvent;
use App\Models\Gate\GateEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Make;
use Tests\Support\SalesFixtures;

beforeEach(function () {
    $this->f = new SalesFixtures(Make::entity('GDV'));
    $this->f->as($this->f->dispatcher);
    $this->devices = app(GateDevices::class);
});

it('matches a plate read to the open vehicle and flags a typed number that differs', function () {
    $entry = app(GateService::class)->arrive(['registration_no' => 'MNA211234']);

    $r = $this->devices->plateRead('mna 21 1234', 9700, str_repeat('e', 64), null, now());
    expect($r['gate_entry']?->id)->toBe($entry->id)->and($r['mismatch'])->toBeFalse();

    $r = $this->devices->plateRead('MNA211284', 6100, null, $entry, now()); // camera read a different plate for this entry
    expect($r['mismatch'])->toBeTrue()
        ->and($entry->fresh()->flags)->toContain('plate_mismatch')
        ->and($entry->fresh()->registration_no)->toBe('MNA211234'); // never "corrected"
});

it('opens the barrier only by the rules and logs every decision', function () {
    $gate = app(GateService::class);
    $inbound = $gate->arrive(['registration_no' => 'MNA211234']);

    expect($this->devices->barrier($inbound, 'in', now()))->toMatchArray(['open' => true, 'reason' => 'arrival_registered'])
        ->and($this->devices->barrier($inbound, 'out', now()))->toMatchArray(['open' => false, 'reason' => 'no_slip'])
        ->and($this->devices->barrier(null, 'in', now()))->toMatchArray(['open' => false, 'reason' => 'no_matching_vehicle']);

    $entry = $this->f->loadAndWeigh($this->f->order());
    expect($this->devices->barrier($entry, 'out', now()))->toMatchArray(['open' => false, 'reason' => 'no_gate_pass']);
    $this->f->as($this->f->acc->accountant);
    app(SalesBillService::class)->post($this->f->billFor($entry));
    $this->f->as($this->f->dispatcher);
    $gate->issueGatePass($entry);
    expect(app(GateDevices::class)->barrier($entry, 'out', now()))->toMatchArray(['open' => true, 'reason' => 'gate_pass_issued']);

    expect(GateDeviceEvent::query()->where('kind', 'barrier_refused')->count())->toBe(3)
        ->and(GateDeviceEvent::query()->where('kind', 'barrier_opened')->count())->toBe(2);
});

it('records a manual open with a reason, flags the vehicle and audits it', function () {
    $entry = app(GateService::class)->arrive(['registration_no' => 'MNA211234']);
    expect(fn () => $this->devices->manualOpen($entry, 'out', 'oops', now()))->toThrow(BusinessRuleViolation::class, 'reason');

    $this->devices->manualOpen($entry, 'out', 'Power cut, ambulance waiting behind', now());
    expect($entry->fresh()->flags)->toContain('barrier_manual_open')
        ->and(AuditLog::query()->where('action', 'gate.barrier.manual_open')->exists())->toBeTrue();

    // The hardware log is append-only (last: PostgreSQL aborts the test transaction on the rejected statement).
    expect(fn () => DB::table('gate_device_events')->update(['reason' => 'edited']))->toThrow(QueryException::class);
});

it('answers the agent over the signed API and takes plate reads and manual opens by sync', function () {
    $headers = Make::apiHeaders(Make::apiTokens($this->f->dispatcher, 'gate-agent-0001-abcd'), 'gate-agent-0001-abcd');
    $this->f->as($this->f->dispatcher);
    $entry = app(GateService::class)->arrive(['registration_no' => 'MNA211234']);

    $this->withHeaders($headers)->postJson('/api/v1/gate/barrier', ['side' => 'in', 'plate' => 'MNA 21 1234'])
        ->assertOk()->assertJson(['open' => true, 'gate_entry_id' => $entry->id]);
    $this->withHeaders($headers)->postJson('/api/v1/gate/barrier', ['side' => 'out', 'gate_entry_id' => $entry->id])
        ->assertOk()->assertJson(['open' => false, 'reason' => 'no_slip']);

    $seq = 0;
    $push = function (string $type, array $payload) use ($headers, &$seq): array {
        return $this->withHeaders($headers)->postJson('/api/v1/sync/push', ['events' => [[
            'event_id' => (string) Str::uuid(), 'client_seq' => ++$seq, 'occurred_at' => now()->toIso8601ZuluString('millisecond'),
            'type' => $type, 'record_id' => (string) Str::uuid(), 'payload' => $payload,
        ]]])->assertOk()->json('results.0');
    };

    expect($push('gate_entry.plate_read', ['plate' => 'MNA211234', 'confidence_bp' => 9800]))->toMatchArray(['status' => 'applied']);
    expect($push('barrier.manual_open', ['side' => 'out', 'reason' => 'Server unreachable, perishable load', 'gate_entry_id' => $entry->id]))
        ->toMatchArray(['status' => 'applied']);

    Make::system(function () use ($entry) {
        expect(GateDeviceEvent::query()->where('kind', 'plate_read')->sole()->gate_entry_id)->toBe($entry->id)
            ->and(GateEntry::query()->find($entry->id)->flags)->toContain('barrier_manual_open');
    });
});
