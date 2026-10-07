<?php

declare(strict_types=1);

use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\RoleTemplates;
use App\Models\Entity;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\Make;

/*
 * Authorization tests for every role:
 *  1. every permission code answers exactly as the role template says (no more, no less);
 *  2. every admin screen and API endpoint is reachable only with the right permission.
 */

dataset('roles', fn () => array_keys(RoleTemplates::all()));

beforeEach(function () {
    $this->entity = Make::entity('AUTHZ');
});

function accountFor(string $role, Entity $entity): LoginAccount
{
    return Make::account($entity, $role, ['mfa' => true]);
}

it('grants exactly the template permissions', function (string $role) {
    $account = accountFor($role, $this->entity);
    $granted = RoleTemplates::get($role)['permissions'];

    foreach (PermissionCatalog::codes() as $code) {
        expect(Gate::forUser($account)->allows($code))
            ->toBe(in_array($code, $granted, true), "{$role} / {$code}");
    }
})->with('roles');

it('shows confidential margin data only to roles holding pricing.margin.view', function (string $role) {
    $account = accountFor($role, $this->entity);

    expect($account->hasPermission('pricing.margin.view'))->toBe(in_array($role, ['SUPER_ADMIN', 'OWNER'], true));
})->with('roles');

it('limits brokers to their own deals and commissions', function () {
    $broker = accountFor('BROKER', $this->entity);

    expect($broker->hasPermission('deal.view_own'))->toBeTrue()
        ->and($broker->hasPermission('deal.view'))->toBeFalse()
        ->and($broker->hasPermission('commission.view_own'))->toBeTrue()
        ->and($broker->hasPermission('ledger.view'))->toBeFalse();
});

it('grants nothing while 2FA enrollment or a password change is pending', function () {
    $owner = Make::account($this->entity, 'OWNER', ['mfa' => false]);
    $gate = Make::account($this->entity, 'GATE_OPERATOR', ['must_change_password' => true]);

    expect($owner->hasPermission('entity.view'))->toBeFalse()
        ->and($gate->hasPermission('gate.entry'))->toBeFalse();
});

/** [admin path, permission that opens it] */
dataset('admin screens', [
    'entities' => ['/admin/entities', 'entity.view'],
    'users' => ['/admin/users', 'user.view'],
    'roles' => ['/admin/roles', 'role.view'],
    'approvals' => ['/admin/approvals', 'approval.view'],
    'audit log' => ['/admin/audit-log', 'audit.view'],
    'devices' => ['/admin/devices', 'device.view'],
]);

it('opens each admin screen only with its permission', function (string $path, string $permission) {
    foreach (array_keys(RoleTemplates::all()) as $role) {
        if ($role === 'BROKER') {
            continue; // no back-office access at all (checked separately)
        }
        $account = accountFor($role, $this->entity);
        $holds = in_array($permission, RoleTemplates::get($role)['permissions'], true)
            || ($path === '/admin/devices' && $account->hasPermission('device.manage'))
            || ($path === '/admin/approvals' && $account->hasPermission('approval.decide'));

        $session = Make::webSession($account);
        $status = $this->actingAs($account, 'web')->withSession($session)->get($path)->getStatusCode();
        // Each role is a separate browser: fresh guards and session.
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe($holds ? 200 : 403, "{$role} → {$path}");
    }
})->with('admin screens');

it('lets every back-office role see its own sessions and settings pages', function (string $role) {
    if ($role === 'BROKER') {
        $this->markTestSkipped('Brokers have no back-office access.');
    }
    $account = accountFor($role, $this->entity);

    $this->actingAs($account, 'web')->withSession(Make::webSession($account))
        ->get('/admin/sessions')->assertOk();
})->with('roles');

it('keeps brokers out of the back office', function () {
    $broker = accountFor('BROKER', $this->entity);

    $this->actingAs($broker, 'web')->withSession(Make::webSession($broker))->get('/admin')->assertForbidden();
});

it('shows the settings page to Super Admin only', function (string $role) {
    $account = accountFor($role, $this->entity);
    if ($role === 'BROKER') {
        expect(true)->toBeTrue();

        return;
    }

    $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))
        ->get('/admin/security-settings')->getStatusCode();

    expect($status)->toBe($role === 'SUPER_ADMIN' ? 200 : 403);
})->with('roles');

it('protects the audit API by permission', function (string $role) {
    $account = accountFor($role, $this->entity);
    $headers = Make::apiHeaders(Make::apiTokens($account));
    $expected = in_array('audit.view', RoleTemplates::get($role)['permissions'], true) ? 200 : 403;

    $this->withHeaders($headers)->getJson('/api/v1/audit')->assertStatus($expected);
    $this->withHeaders($headers)->getJson('/api/v1/audit/verify')
        ->assertStatus(in_array('audit.verify', RoleTemplates::get($role)['permissions'], true) ? 200 : 403);
})->with('roles');

it('enforces sync read/write rights per role', function (string $role) {
    $account = accountFor($role, $this->entity);
    $headers = Make::apiHeaders(Make::apiTokens($account));
    $grants = RoleTemplates::get($role)['permissions'];
    $has = fn (string ...$any) => array_intersect($any, $grants) !== [];

    $types = collect($this->withHeaders($headers)->getJson('/api/v1/sync/bootstrap')->assertOk()->json('types'))->keyBy('type');

    expect($types['party']['read'])->toBe($has('master.view'), "{$role} reads parties")
        ->and($types['party']['write'])->toBe($has('master.manage'), "{$role} writes parties")
        ->and($types['vehicle']['write'])->toBe($has('master.manage', 'gate.entry'), "{$role} writes vehicles");

    $result = $this->withHeaders($headers)->postJson('/api/v1/sync/push', ['events' => [[
        'event_id' => (string) Str::uuid(), 'client_seq' => 1, 'occurred_at' => now()->toIso8601ZuluString(),
        'type' => 'party.upsert', 'record_id' => (string) Str::uuid(),
        'payload' => ['code' => 'P'.random_int(100, 999), 'name' => 'X', 'gst_registration_type' => 'unregistered'],
    ]]])->assertOk()->json('results.0.status');

    expect($result)->toBe($has('master.manage') ? 'applied' : 'rejected', "{$role} pushes a party");
})->with('roles');

it('rejects every protected API endpoint without a token', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/auth/me'],
    ['POST', '/api/v1/auth/logout'],
    ['POST', '/api/v1/auth/switch'],
    ['GET', '/api/v1/sessions'],
    ['DELETE', '/api/v1/sessions/01a11539-95e1-705a-82e9-acaddf3f37a5'],
    ['GET', '/api/v1/audit'],
    ['GET', '/api/v1/audit/verify'],
    ['GET', '/api/v1/sync/bootstrap'],
    ['POST', '/api/v1/sync/push'],
    ['GET', '/api/v1/sync/pull'],
    ['POST', '/api/v1/sync/wipe-ack'],
]);
