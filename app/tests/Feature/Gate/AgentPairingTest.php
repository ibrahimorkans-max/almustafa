<?php

declare(strict_types=1);

use App\Domain\Gate\AgentPairing;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Middleware\VerifyAgentSignature;
use App\Models\Device;
use App\Models\Gate\AgentPairing as Pairing;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('PAR'));
    $this->f->as($this->f->owner);
    $this->pairing = app(AgentPairing::class);
});

/** @return array<string, string> */
function pairSigned(string $secret, string $access, string $uid, string $method, string $uri, string $body): array
{
    $ts = (string) time();
    $nonce = (string) Str::uuid();

    return [
        'Authorization' => 'Bearer '.$access, 'X-Device-Id' => $uid, 'Accept' => 'application/json',
        'X-Timestamp' => $ts, 'X-Nonce' => $nonce,
        'X-Signature' => hash_hmac('sha256', VerifyAgentSignature::canonical($ts, $nonce, $method, $uri, $body), $secret),
    ];
}

it('pairs a weighbridge PC with one code: new login, device and weighbridge, then it signs in and sends live weight', function () {
    $r = $this->pairing->issue($this->f->scale->id, null, 'Kanta PC');
    expect($r['code'])->toMatch('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/')
        ->and($r['username'])->toBe('wb.wb1')->and($r['new_login'])->toBeTrue();

    // Typed on the PC in small letters without the dash: still the same code.
    $code = strtolower(str_replace('-', '', $r['code']));
    $s = $this->postJson('/api/v1/agent/pair', ['code' => $code])->assertOk()->json();
    expect($s)->toMatchArray(['status' => 'ok', 'username' => 'wb.wb1', 'scale_id' => $this->f->scale->id, 'scale_code' => 'WB1'])
        ->and($s['device_uid'])->toStartWith('agent-')->and(strlen($s['password']))->toBe(32);

    // Only once; the stored settings are erased.
    $this->postJson('/api/v1/agent/pair', ['code' => $code])->assertStatus(422)->assertJsonPath('error', 'pairing_code_invalid');
    expect(Make::system(fn () => Pairing::query()->sole()->payload))->toBeNull();

    $login = $this->postJson('/api/v1/auth/login', [
        'username' => $s['username'], 'password' => $s['password'], 'device_uid' => $s['device_uid'], 'platform' => 'agent',
    ])->assertOk();
    $access = $login->json('access_token');
    $body = json_encode(['scale_id' => $s['scale_id'], 'kg' => '12340', 'stable' => false]);
    $this->call('POST', '/api/v1/gate/live-weight', [], [], [], $this->transformHeadersToServerVars(
        pairSigned($s['signing_secret'], $access, $s['device_uid'], 'POST', '/api/v1/gate/live-weight', $body) + ['Content-Type' => 'application/json']
    ), $body)->assertOk();
});

it('refuses wrong and expired codes', function () {
    $this->postJson('/api/v1/agent/pair', ['code' => 'ABCD-EFGH'])->assertStatus(422);

    $this->f->as($this->f->owner);   // an API call ends the signed-in context
    $r = $this->pairing->issue($this->f->scale->id);
    $this->travel(31)->minutes();
    $this->postJson('/api/v1/agent/pair', ['code' => $r['code']])->assertStatus(422);
});

it('pairs again with the same login: new password and device, the old ones stop working', function () {
    $first = $this->pairing->issue($this->f->scale->id);
    $old = $this->postJson('/api/v1/agent/pair', ['code' => $first['code']])->json();

    $this->f->as($this->f->owner);
    $account = Make::system(fn () => LoginAccount::query()->where('username', $old['username'])->sole());
    $second = $this->pairing->issue($this->f->scale->id, $account->id);
    expect($second['new_login'])->toBeFalse()->and($second['username'])->toBe($old['username']);
    $new = $this->postJson('/api/v1/agent/pair', ['code' => $second['code']])->json();

    expect(Make::system(fn () => Device::query()->where('device_uid', $old['device_uid'])->value('status')))->not->toBe(Device::STATUS_ACTIVE);
    $this->postJson('/api/v1/auth/login', ['username' => $old['username'], 'password' => $old['password'], 'device_uid' => $old['device_uid'], 'platform' => 'agent'])
        ->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['username' => $new['username'], 'password' => $new['password'], 'device_uid' => $new['device_uid'], 'platform' => 'agent'])
        ->assertOk();
});

it('pairing a gate login locked by wrong passwords unlocks it, so the PC can sign in', function () {
    $gate = Make::account($this->f->entity, 'GATE_OPERATOR', ['username' => 'gate.pc']);
    // Locked by the PC retrying an old password: the whole account, and its address.
    Make::system(function () use ($gate) {
        LoginAccount::query()->whereKey($gate->id)->update(['needs_admin_unlock' => true, 'locked_until' => now()->addMinutes(15), 'lockouts_24h' => 3]);
        DB::table('login_address_locks')->insert(['login_account_id' => $gate->id, 'ip_hash' => hash('sha256', config('app.key').'|127.0.0.1'),
            'failures' => 0, 'locked_until' => now()->addMinutes(15), 'updated_at' => now()]);
    });

    $this->f->as($this->f->owner);
    $r = $this->pairing->issue($this->f->scale->id, $gate->id);
    $s = $this->postJson('/api/v1/agent/pair', ['code' => $r['code']])->assertOk()->json();
    $this->postJson('/api/v1/auth/login', ['username' => $s['username'], 'password' => $s['password'], 'device_uid' => $s['device_uid'], 'platform' => 'agent'])
        ->assertOk();
});

it('lets only those who manage devices make codes, and only for gate logins', function () {
    $clerk = Make::account($this->f->entity, 'GATE_OPERATOR', ['username' => 'gate.person']);
    $this->f->as($clerk);
    expect(fn () => $this->pairing->issue($this->f->scale->id))->toThrow(BusinessRuleViolation::class);

    $this->f->as($this->f->owner);
    $accountant = Make::account($this->f->entity, 'ACCOUNTANT', ['username' => 'acc.one']);
    expect(fn () => $this->pairing->issue($this->f->scale->id, $accountant->id))->toThrow(BusinessRuleViolation::class, 'Weighbridge / Gate Operator');
});
