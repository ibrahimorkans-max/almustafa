<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceService;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncEvent;
use App\Domain\Sync\SyncOutcome;
use App\Domain\Sync\SyncRegistry;
use App\Domain\Sync\SyncService;
use App\Models\Device;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use App\Models\SyncConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Make;

const DEV = 'test-device-0001-abcdef';

beforeEach(function () {
    $this->entity = Make::entity('SYN');
    $this->gate = Make::account($this->entity, 'GATE_OPERATOR');
    $this->headers = Make::apiHeaders(Make::apiTokens($this->gate, DEV), DEV);
    $this->seq = 0;
    // A new mill already has its default records (e.g. sales-tax rates) in the feed; tests read what comes after.
    $this->start = pullChanges($this)['next_cursor'];
});

function syncEvent(object $test, string $type, ?string $recordId, array $payload, ?int $baseVersion = null, ?string $occurredAt = null): array
{
    return [
        'event_id' => (string) Str::uuid(),
        'client_seq' => ++$test->seq,
        'occurred_at' => $occurredAt ?? now()->toIso8601ZuluString('millisecond'),
        'type' => $type,
        'record_id' => $recordId,
        'base_version' => $baseVersion,
        'payload' => $payload,
    ];
}

function pushEvents(object $test, array ...$events): array
{
    return $test->withHeaders($test->headers)->postJson('/api/v1/sync/push', ['events' => $events])->assertOk()->json('results');
}

function pullChanges(object $test, int $cursor = 0, ?array $headers = null, int $limit = 500): array
{
    return $test->withHeaders($headers ?? $test->headers)->getJson("/api/v1/sync/pull?cursor={$cursor}&limit={$limit}")->assertOk()->json();
}

function serverVehicle(string $id): Vehicle
{
    return Make::system(fn () => Vehicle::query()->withArchived()->findOrFail($id));
}

it('describes the contract in bootstrap', function () {
    $this->withHeaders($this->headers)->getJson('/api/v1/sync/bootstrap')->assertOk()
        ->assertJsonPath('protocol_version', 1)
        ->assertJsonPath('max_offline_hours', 72)
        ->assertJsonFragment(['type' => 'vehicle', 'read' => true, 'write' => true])
        ->assertJsonFragment(['type' => 'party', 'read' => true, 'write' => false]);
});

it('creates a record offline-first with the client UUID and serves it on pull', function () {
    $id = (string) Str::uuid();
    [$result] = pushEvents($this, syncEvent($this, 'vehicle.upsert', $id, ['registration_no' => 'mna 21 1234', 'vehicle_type' => 'truck']));

    expect($result)->toMatchArray(['status' => 'applied', 'version' => 1])
        ->and(serverVehicle($id)->registration_no)->toBe('MNA211234');

    $feed = pullChanges($this, $this->start);
    expect($feed['changes'])->toHaveCount(1)
        ->and($feed['changes'][0])->toMatchArray(['type' => 'vehicle', 'op' => 'upsert', 'id' => $id, 'version' => 1])
        ->and($feed['changes'][0]['data']['registration_no'])->toBe('MNA211234')
        ->and(pullChanges($this, $feed['next_cursor'])['changes'])->toBe([]);
});

it('is idempotent: a re-sent event returns the stored result and changes nothing', function () {
    $id = (string) Str::uuid();
    $ev = syncEvent($this, 'vehicle.upsert', $id, ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']);

    $first = pushEvents($this, $ev)[0];
    $again = pushEvents($this, $ev)[0];

    expect($again)->toMatchArray(['status' => 'applied', 'version' => 1, 'duplicate' => true])
        ->and($first['status'])->toBe('applied')
        ->and(serverVehicle($id)->version)->toBe(1)
        ->and(Make::system(fn () => DB::table('sync_events')->count()))->toBe(1);
});

it('rejects a client_seq already used by another event of the device', function () {
    $a = syncEvent($this, 'vehicle.upsert', (string) Str::uuid(), ['registration_no' => 'MNA211111', 'vehicle_type' => 'truck']);
    pushEvents($this, $a);
    $old = ['client_seq' => 1] + syncEvent($this, 'vehicle.upsert', (string) Str::uuid(), ['registration_no' => 'MNA212222', 'vehicle_type' => 'truck']);

    expect(pushEvents($this, $old)[0])->toMatchArray(['status' => 'rejected', 'reason' => 'client_seq_reused']);
});

it('rejects invalid data with field errors, unknown types and forbidden writes', function () {
    [$invalid, $unknown, $forbidden] = pushEvents($this,
        syncEvent($this, 'vehicle.upsert', (string) Str::uuid(), ['registration_no' => 'NOT A PLATE', 'vehicle_type' => 'truck']),
        syncEvent($this, 'spaceship.upsert', (string) Str::uuid(), []),
        syncEvent($this, 'party.upsert', (string) Str::uuid(), ['code' => 'P1', 'name' => 'X']),
    );

    expect($invalid)->toMatchArray(['status' => 'rejected', 'reason' => 'validation_failed'])
        ->and($invalid['errors'])->toHaveKey('registration_no')
        ->and($unknown['reason'])->toBe('unknown_event_type')
        ->and($forbidden['reason'])->toBe('forbidden');
});

it('applies an update made on the current version', function () {
    $id = (string) Str::uuid();
    pushEvents($this, syncEvent($this, 'vehicle.upsert', $id, ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']));
    [$r] = pushEvents($this, syncEvent($this, 'vehicle.upsert', $id, ['capacity_kg' => '25000.500'], baseVersion: 1));

    expect($r)->toMatchArray(['status' => 'applied', 'version' => 2])
        ->and(serverVehicle($id)->capacity_kg)->toBe('25000.500')
        ->and(serverVehicle($id)->vehicle_type)->toBe('truck'); // partial payload keeps other fields
});

describe('conflicts', function () {
    beforeEach(function () {
        $this->id = (string) Str::uuid();
        pushEvents($this, syncEvent($this, 'vehicle.upsert', $this->id, ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']));
        // Someone edits on the server meanwhile → version 2.
        $this->travel(1)->minutes();
        Make::system(function () {
            app(AuthContextBinder::class)->bind($this->gate);
            serverVehicle($this->id)->fill(['vehicle_type' => 'tractor'])->save();
        });
        Make::reset();
    });

    it('lets the later write win and logs the overwritten server values', function () {
        [$r] = pushEvents($this, syncEvent($this, 'vehicle.upsert', $this->id, ['vehicle_type' => 'tempo'], baseVersion: 1, occurredAt: now()->addSeconds(10)->toIso8601ZuluString()));

        expect($r)->toMatchArray(['status' => 'conflict_client_won', 'version' => 3])
            ->and(serverVehicle($this->id)->vehicle_type)->toBe('tempo');
        $conflict = Make::system(fn () => SyncConflict::query()->sole());
        expect($conflict->resolution)->toBe('client_won')
            ->and($conflict->server_snapshot['vehicle_type'])->toBe('tractor')
            ->and($conflict->review_status)->toBeNull();
    });

    it('keeps the server version when the device edit is older and returns it', function () {
        [$r] = pushEvents($this, syncEvent($this, 'vehicle.upsert', $this->id, ['vehicle_type' => 'tempo'], baseVersion: 1, occurredAt: now()->subMinutes(5)->toIso8601ZuluString()));

        expect($r)->toMatchArray(['status' => 'conflict_server_won', 'version' => 2])
            ->and($r['record']['vehicle_type'])->toBe('tractor')
            ->and(serverVehicle($this->id)->vehicle_type)->toBe('tractor')
            ->and(Make::system(fn () => SyncConflict::query()->sole()->client_payload))->toBe(['vehicle_type' => 'tempo']);
    });

    it('does not let a device clock set in the future win every conflict', function () {
        [$r] = pushEvents($this, syncEvent($this, 'vehicle.upsert', $this->id, ['vehicle_type' => 'tempo'], baseVersion: 1, occurredAt: now()->addYear()->toIso8601ZuluString()));

        // Clamped to receipt time, which is still after the server edit → client wins, but stored time is sane.
        expect($r['status'])->toBe('conflict_client_won')
            ->and(Make::system(fn () => DB::table('sync_events')->where('client_seq', $this->seq)->value('occurred_at')))
            ->toStartWith(now()->format('Y-m-d'));
    });
});

describe('supervisor review queue', function () {
    it('sends a duplicate registration number to review instead of failing silently', function () {
        pushEvents($this, syncEvent($this, 'vehicle.upsert', (string) Str::uuid(), ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']));
        [$r] = pushEvents($this, syncEvent($this, 'vehicle.upsert', (string) Str::uuid(), ['registration_no' => 'MNA-21-1234', 'vehicle_type' => 'truck']));

        expect($r)->toMatchArray(['status' => 'needs_review', 'reason' => 'duplicate_value'])
            ->and(Make::system(fn () => SyncConflict::query()->where('review_status', 'open')->count()))->toBe(1)
            // the batch transaction survived the failed insert
            ->and(Make::system(fn () => Vehicle::query()->count()))->toBe(1);
    });

    it('sends edits of a record archived on the server to review', function () {
        $id = (string) Str::uuid();
        pushEvents($this, syncEvent($this, 'vehicle.upsert', $id, ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']));
        Make::system(function () use ($id) {
            app(AuthContextBinder::class)->bind($this->gate);
            serverVehicle($id)->forceFill(['archived_at' => now()])->save();
        });
        Make::reset();

        [$r] = pushEvents($this, syncEvent($this, 'vehicle.upsert', $id, ['vehicle_type' => 'tempo'], baseVersion: 2));
        expect($r)->toMatchArray(['status' => 'needs_review', 'reason' => 'archived_on_server']);
    });

    it('sends events older than the offline limit to review', function () {
        [$r] = pushEvents($this, syncEvent($this, 'vehicle.upsert', (string) Str::uuid(), ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck'], occurredAt: now()->subHours(80)->toIso8601ZuluString()));

        expect($r)->toMatchArray(['status' => 'needs_review', 'reason' => 'older_than_offline_limit'])
            ->and(Make::system(fn () => Vehicle::query()->count()))->toBe(0);
    });
});

it('holds online-only actions as pending_online', function () {
    app(SyncRegistry::class)->register(new class implements SyncHandler
    {
        public function type(): string
        {
            return 'payment.request';
        }

        public function canWrite(LoginAccount $account): bool
        {
            return true;
        }

        public function requiresOnlineConfirmation(array $payload): bool
        {
            return ($payload['amount_paise'] ?? 0) > 10_000_000;
        }

        public function apply(SyncEvent $event, LoginAccount $account): SyncOutcome
        {
            return new SyncOutcome(SyncOutcome::APPLIED);
        }
    });

    // Service level: the HTTP test harness resets request-scoped services between requests.
    app(AuthContextBinder::class)->bind($this->gate);
    $device = Device::query()->where('device_uid', DEV)->sole();
    $results = app(SyncService::class)->push($this->gate, $device, [
        syncEvent($this, 'payment.request', null, ['amount_paise' => 50_000_000]),
        syncEvent($this, 'payment.request', null, ['amount_paise' => 1_000]),
    ]);

    expect($results[0]['status'])->toBe('pending_online')->and($results[1]['status'])->toBe('applied');
});

describe('pull', function () {
    it('returns the latest state once per record, in pages, with a stable cursor', function () {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $id = (string) Str::uuid();
            pushEvents($this, syncEvent($this, 'vehicle.upsert', $id, ['registration_no' => "MNA21100{$i}", 'vehicle_type' => 'truck']));
        }
        pushEvents($this, syncEvent($this, 'vehicle.upsert', $ids[0], ['vehicle_type' => 'tempo'], baseVersion: 1));

        $page1 = pullChanges($this, $this->start, limit: 4);
        $page2 = pullChanges($this, $page1['next_cursor'], limit: 4);

        expect($page1['has_more'])->toBeTrue()
            ->and($page2['has_more'])->toBeFalse()
            ->and(collect([...$page1['changes'], ...$page2['changes']])->pluck('id')->unique()->count())->toBe(5)
            ->and(collect($page2['changes'])->firstWhere('id', $ids[0])['data']['vehicle_type'])->toBe('tempo');
    });

    it('tells devices to drop archived records', function () {
        $id = (string) Str::uuid();
        pushEvents($this, syncEvent($this, 'vehicle.upsert', $id, ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']));
        $cursor = pullChanges($this)['next_cursor'];
        Make::system(function () use ($id) {
            app(AuthContextBinder::class)->bind($this->gate);
            serverVehicle($id)->forceFill(['archived_at' => now()])->save();
        });
        Make::reset();

        $changes = pullChanges($this, $cursor)['changes'];
        expect($changes)->toHaveCount(1)->and($changes[0])->toMatchArray(['op' => 'archive', 'id' => $id])
            ->and($changes[0])->not->toHaveKey('data');
    });

    it('hides confidential fields from viewers without permission', function () {
        $party = Make::system(function () {
            app(AuthContextBinder::class)->bind(Make::account($this->entity, 'SUPER_ADMIN'));

            return Party::query()->create([
                'code' => 'V1', 'name' => 'Lodhran Traders', 'is_vendor' => true,
                'strn' => '3277876543210', 'cnic' => '3620312345671',
            ]);
        });
        Make::reset();

        $asGate = collect(pullChanges($this)['changes'])->firstWhere('id', $party->id)['data'];
        $accountant = Make::account($this->entity, 'ACCOUNTANT');
        $asAccountant = collect(pullChanges($this, 0, Make::apiHeaders(Make::apiTokens($accountant, 'acc-device-0001-abcd'), 'acc-device-0001-abcd'))['changes'])
            ->firstWhere('id', $party->id)['data'];

        expect($asGate['strn'])->toBe('3277876543210')
            ->and($asGate['cnic'])->toBe('*********5671')
            ->and($asAccountant['cnic'])->toBe('3620312345671');
    });

    it('never serves another entity\'s changes, and cannot overwrite them by guessing an id', function () {
        $other = Make::entity('OTH');
        $otherGate = Make::account($other, 'GATE_OPERATOR');
        $otherHeaders = Make::apiHeaders(Make::apiTokens($otherGate, 'other-device-0001-abc'), 'other-device-0001-abc');
        $id = (string) Str::uuid();
        $this->withHeaders($otherHeaders)->postJson('/api/v1/sync/push', ['events' => [
            ['event_id' => (string) Str::uuid(), 'client_seq' => 1, 'occurred_at' => now()->toIso8601ZuluString(), 'type' => 'vehicle.upsert',
                'record_id' => $id, 'payload' => ['registration_no' => 'BKX9999', 'vehicle_type' => 'truck']],
        ]])->assertOk();

        expect(pullChanges($this, $this->start)['changes'])->toBe([]);

        [$r] = pushEvents($this, syncEvent($this, 'vehicle.upsert', $id, ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']));
        expect($r['status'])->toBeIn(['needs_review', 'rejected'])
            ->and(serverVehicle($id)->registration_no)->toBe('BKX9999')
            ->and(serverVehicle($id)->entity_id)->toBe($other->id);
    });

    it('numbers the feed without gaps', function () {
        foreach (range(1, 3) as $i) {
            pushEvents($this, syncEvent($this, 'vehicle.upsert', (string) Str::uuid(), ['registration_no' => "MNA21200{$i}", 'vehicle_type' => 'truck']));
        }
        $seqs = Make::system(fn () => DB::table('sync_changes')->where('entity_id', $this->entity->id)->orderBy('seq')->pluck('seq')->map(fn ($s) => (int) $s)->all());

        expect($seqs)->toBe(range(1, count($seqs)));
    });
});

describe('remote wipe', function () {
    it('tells the device to wipe, then ends its sessions after the acknowledgement', function () {
        Make::system(fn () => app(DeviceService::class)->requestWipe(Device::query()->where('device_uid', DEV)->sole(), 'phone lost'));

        $this->withHeaders($this->headers)->getJson('/api/v1/sync/pull')->assertStatus(403)->assertJsonPath('error', 'wipe_required');
        $this->withHeaders($this->headers)->getJson('/api/v1/auth/me')->assertStatus(403);

        $this->withHeaders($this->headers)->postJson('/api/v1/sync/wipe-ack')->assertOk()->assertJsonPath('status', 'wiped');

        $this->withHeaders($this->headers)->getJson('/api/v1/sync/pull')->assertUnauthorized();
        expect(Make::system(fn () => Device::query()->where('device_uid', DEV)->sole()->status))->toBe('wiped');
    });
});

it('keeps the sync log append-only', function () {
    pushEvents($this, syncEvent($this, 'vehicle.upsert', (string) Str::uuid(), ['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']));

    expect(fn () => Make::system(fn () => DB::transaction(fn () => DB::table('sync_events')->update(['status' => 'applied']))))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => Make::system(fn () => DB::transaction(fn () => DB::table('sync_changes')->delete())))
        ->toThrow(QueryException::class, 'append-only');
});
