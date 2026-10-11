<?php

declare(strict_types=1);

use App\Domain\Auth\DeviceService;
use App\Http\Middleware\VerifyAgentSignature;
use App\Models\AuditLog;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\Weighment;
use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('GSY'));
    $this->sauda = $this->f->confirmedSauda();
    $this->sup = Make::account($this->f->entity, 'SUPERVISOR');
    $this->headers = Make::apiHeaders(Make::apiTokens($this->sup, 'gate-tablet-0001-abcd'), 'gate-tablet-0001-abcd');
    $this->seq = 0;
});

function ev(object $t, string $type, ?string $recordId, array $payload, ?string $at = null): array
{
    return [
        'event_id' => (string) Str::uuid(), 'client_seq' => ++$t->seq, 'occurred_at' => $at ?? now()->toIso8601ZuluString('millisecond'),
        'type' => $type, 'record_id' => $recordId, 'payload' => $payload,
    ];
}

function send(object $t, array ...$events): array
{
    return $t->withHeaders($t->headers)->postJson('/api/v1/sync/push', ['events' => $events])->assertOk()->json('results');
}

it('accepts gate facts from devices and projects them', function () {
    $entryId = (string) Str::uuid();
    [$arrived, $gross] = send($this,
        ev($this, 'gate_entry.arrived', $entryId, ['registration_no' => 'MNA211234', 'sauda_id' => $this->sauda->id, 'bag_count' => 300]),
        ev($this, 'weighment.captured', (string) Str::uuid(), [
            'gate_entry_id' => $entryId, 'kind' => 'gross', 'weight_kg' => '24000', 'scale_id' => $this->f->scale->id,
            'readings' => GateFixtures::readings('24000'), 'photo_sha256' => str_repeat('b', 64),
        ]),
    );

    expect($arrived['status'])->toBe('applied')->and($arrived['number'])->toStartWith('GSY/G/')
        ->and($gross['status'])->toBe('applied');

    $entry = Make::system(fn () => GateEntry::query()->findOrFail($entryId));
    expect($entry->status)->toBe('gross_weighed')->and($entry->gross_kg)->toBe('24000.000');
});

it('answers retry for a fact about a vehicle not uploaded yet, then applies it on resend', function () {
    $entryId = (string) Str::uuid();
    $weighment = ev($this, 'weighment.captured', (string) Str::uuid(), [
        'gate_entry_id' => $entryId, 'kind' => 'gross', 'weight_kg' => '24000', 'readings' => GateFixtures::readings('24000'),
    ]);

    [$r] = send($this, $weighment);
    expect($r)->toMatchArray(['status' => 'retry', 'reason' => 'gate_entry_not_yet_synced'])
        ->and(Make::system(fn () => Weighment::query()->count()))->toBe(0);

    // Another device (gate tablet) uploads the arrival; the agent re-sends the same event and seq.
    send($this, ev($this, 'gate_entry.arrived', $entryId, ['registration_no' => 'MNA211234']));
    [$again] = send($this, $weighment);

    expect($again['status'])->toBe('applied')
        ->and(Make::system(fn () => Weighment::query()->count()))->toBe(1);
});

it('keeps out-of-order facts from offline devices and marks them', function () {
    $entryId = (string) Str::uuid();
    send($this, ev($this, 'gate_entry.arrived', $entryId, ['registration_no' => 'MNA211234']));
    // Unloaded reported before any lab decision (devices were offline) — a fact, not an error.
    [$r] = send($this, ev($this, 'gate_entry.event', null, ['gate_entry_id' => $entryId, 'event' => 'unloaded']));

    expect($r['status'])->toBe('applied')->and($r['anomaly'])->toContain('before');
    $entry = Make::system(fn () => GateEntry::query()->findOrFail($entryId));
    expect($entry->flags)->toContain('out_of_order')
        ->and(Make::system(fn () => GateEvent::query()->where('gate_entry_id', $entryId)->where('event', 'unloaded')->exists()))->toBeTrue();
});

it('is idempotent for facts re-sent under a new event id', function () {
    $entryId = (string) Str::uuid();
    send($this, ev($this, 'gate_entry.arrived', $entryId, ['registration_no' => 'MNA211234']));
    [$dup] = send($this, ev($this, 'gate_entry.arrived', $entryId, ['registration_no' => 'MNA211234']));

    expect($dup['status'])->toBe('applied')
        ->and(Make::system(fn () => GateEntry::query()->count()))->toBe(1);
});

it('serves gate entries and saudas to gate devices, without the deal rate', function () {
    send($this, ev($this, 'gate_entry.arrived', (string) Str::uuid(), ['registration_no' => 'MNA211234', 'sauda_id' => $this->sauda->id]));
    $gateOp = Make::account($this->f->entity, 'GATE_OPERATOR');
    $headers = Make::apiHeaders(Make::apiTokens($gateOp, 'gate-op-phone-0001-ab'), 'gate-op-phone-0001-ab');

    $changes = collect($this->withHeaders($headers)->getJson('/api/v1/sync/pull')->assertOk()->json('changes'));
    $sauda = $changes->firstWhere('type', 'sauda')['data'];

    expect($changes->pluck('type')->unique()->values()->all())->toContain('gate_entry', 'sauda', 'vehicle')
        ->and($sauda['number'])->toBe($this->sauda->number)
        ->and($sauda)->not->toHaveKey('rate_paise_per_maund');
});

describe('photo upload', function () {
    beforeEach(fn () => Storage::fake('local'));

    it('stores files by content hash, privately and once', function () {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $upload = fn () => $this->withHeaders($this->headers)->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('cam.png', $png)]);

        $first = $upload()->assertCreated()->json();
        $second = $upload()->assertCreated()->json();

        expect($first['sha256'])->toBe(hash('sha256', $png))->and($second['sha256'])->toBe($first['sha256'])
            ->and(Make::system(fn () => StoredFile::query()->count()))->toBe(1);
        Storage::disk('local')->assertExists(Make::system(fn () => StoredFile::query()->sole()->path));
    });

    it('checks the real content type, not the file name', function () {
        $this->withHeaders($this->headers)
            ->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "x";')])
            ->assertStatus(422)->assertJsonPath('error', 'file_rejected');
    });
});

describe('gate agent', function () {
    beforeEach(function () {
        $this->agentAccount = Make::account($this->f->entity, 'GATE_OPERATOR', ['username' => 'gate.agent.1']);
    });

    function loginAgent(object $t, string $uid): TestResponse
    {
        return $t->postJson('/api/v1/auth/login', [
            'username' => 'gate.agent.1', 'password' => Make::PASSWORD, 'device_uid' => $uid, 'platform' => 'agent',
        ]);
    }

    /** @return array<string, string> */
    function signed(string $secret, string $access, string $uid, string $method, string $uri, string $body, ?int $ts = null, ?string $nonce = null): array
    {
        $ts ??= time();
        $nonce ??= (string) Str::uuid();

        return [
            'Authorization' => 'Bearer '.$access, 'X-Device-Id' => $uid, 'Accept' => 'application/json',
            'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce,
            'X-Signature' => hash_hmac('sha256', VerifyAgentSignature::canonical((string) $ts, $nonce, $method, $uri, $body), $secret),
        ];
    }

    it('refuses an agent device that an administrator did not register', function () {
        loginAgent($this, 'agent-pc-unknown-0001')->assertStatus(403)->assertJsonPath('error', 'device_not_allowed');
    });

    it('requires every authenticated agent request to be signed', function () {
        $this->f->as($this->f->owner);
        ['secret' => $secret] = app(DeviceService::class)->registerAgent($this->agentAccount, 'agent-pc-0001-abcdef', 'Gate PC');
        Make::reset();
        $access = loginAgent($this, 'agent-pc-0001-abcdef')->assertOk()->json('access_token');
        $uid = 'agent-pc-0001-abcdef';

        // Bearer token alone is not enough.
        $this->withHeaders(['Authorization' => 'Bearer '.$access, 'X-Device-Id' => $uid])->getJson('/api/v1/sync/bootstrap')
            ->assertStatus(401)->assertJsonPath('error', 'signature_missing_or_expired');

        $headers = signed($secret, $access, $uid, 'GET', '/api/v1/sync/bootstrap', '');
        $this->get('/api/v1/sync/bootstrap', $headers)->assertOk();
        // Same nonce again → replay.
        $this->get('/api/v1/sync/bootstrap', $headers)->assertStatus(401)->assertJsonPath('error', 'nonce_reused');

        // Signature over a different body.
        $body = json_encode(['events' => [ev($this, 'gate_entry.arrived', (string) Str::uuid(), ['registration_no' => 'MNA211234'])]]);
        $tampered = signed($secret, $access, $uid, 'POST', '/api/v1/sync/push', '{"events":[]}');
        $this->call('POST', '/api/v1/sync/push', [], [], [], $this->transformHeadersToServerVars($tampered) + ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);

        $good = signed($secret, $access, $uid, 'POST', '/api/v1/sync/push', $body);
        $response = $this->call('POST', '/api/v1/sync/push', [], [], [], $this->transformHeadersToServerVars($good) + ['CONTENT_TYPE' => 'application/json'], $body);
        $response->assertOk();
        expect($response->json('results.0.status'))->toBe('applied');

        // Stale timestamp.
        $this->get('/api/v1/sync/bootstrap', signed($secret, $access, $uid, 'GET', '/api/v1/sync/bootstrap', '', time() - 900))
            ->assertStatus(401);
    });

    it('accepts a signed raw-body photo upload from the agent', function () {
        Storage::fake('local');
        $this->f->as($this->f->owner);
        ['secret' => $secret] = app(DeviceService::class)->registerAgent($this->agentAccount, 'agent-pc-0003-abcdef', 'Gate PC 3');
        Make::reset();
        $access = loginAgent($this, 'agent-pc-0003-abcdef')->assertOk()->json('access_token');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $headers = signed($secret, $access, 'agent-pc-0003-abcdef', 'POST', '/api/v1/files', $png);
        $this->call('POST', '/api/v1/files', [], [], [], $this->transformHeadersToServerVars($headers) + ['CONTENT_TYPE' => 'image/png'], $png)
            ->assertCreated()->assertJsonPath('sha256', hash('sha256', $png));
    });

    it('never stores the signing secret in clear text', function () {
        $this->f->as($this->f->owner);
        ['secret' => $secret, 'device' => $device] = app(DeviceService::class)->registerAgent($this->agentAccount, 'agent-pc-0002-abcdef', 'Gate PC 2');

        $raw = Make::system(fn () => DB::table('devices')->where('id', $device->id)->value('signing_secret'));
        $audit = Make::system(fn () => AuditLog::query()->where('subject_id', $device->id)->get()->toJson());
        expect($raw)->not->toContain($secret)->and($audit)->not->toContain($secret);
    });
});
