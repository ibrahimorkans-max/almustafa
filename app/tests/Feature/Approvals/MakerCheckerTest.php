<?php

declare(strict_types=1);

use App\Domain\Approvals\Actions\ChangeRolePermissions;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Auth\AuthContextBinder;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use App\Models\Role;
use Illuminate\Validation\ValidationException;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('MC');
    $this->owner = Make::account($this->entity, 'SUPER_ADMIN');
    $this->owner2 = Make::account($this->entity, 'SUPER_ADMIN');
    $this->gateRole = Make::system(fn () => Role::query()->where('entity_id', $this->entity->id)->where('code', 'GATE_OPERATOR')->firstOrFail());
    $this->service = app(ApprovalService::class);
    // A staff maker: the Owner and the mill's Super Admin need no second person (SelfApproval).
    $this->maker = Make::account($this->entity, 'SUPERVISOR');
    Make::system(fn () => DB::table('role_permissions')->insert([
        'role_id' => $this->maker->role_id, 'permission_code' => 'role.manage', 'entity_id' => $this->entity->id, 'created_at' => now(),
    ]));
    $this->maker->role->flushPermissionCache();
});

function as_(LoginAccount $account): void
{
    app(AuthContextBinder::class)->bind($account);
}

function requestGateChange(ApprovalService $service, Role $role, array $permissions): ApprovalRequest
{
    return $service->submit(ChangeRolePermissions::KEY, ['role_id' => $role->id, 'permissions' => $permissions], 'Gate needs lab', 'Night shift');
}

it('changes nothing until a different person approves', function () {
    as_($this->maker);
    $wanted = [...$this->gateRole->permissionCodes(), 'lab.test'];
    $request = requestGateChange($this->service, $this->gateRole, $wanted);

    expect($request->status)->toBe('pending')
        ->and($this->gateRole->fresh()->permissionCodes())->not->toContain('lab.test');

    as_($this->owner2);
    $done = $this->service->approve($request, $this->owner2, 'ok');

    expect($done->status)->toBe('executed')
        ->and($done->checker_person_id)->toBe($this->owner2->person_id)
        ->and($this->gateRole->fresh()->permissionCodes())->toContain('lab.test')
        ->and(AuditLog::query()->where('action', 'role.permissions.changed')->exists())->toBeTrue();
});

it('does not let the maker approve their own request', function () {
    as_($this->maker);
    $request = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);

    expect(fn () => $this->service->approve($request, $this->maker))->toThrow(ApprovalDenied::class, 'maker-checker');
});

it('does not let the same person approve through a second login account', function () {
    $other = Make::entity('MC2');
    as_($this->maker);
    $request = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);

    // Same human, Owner account in another entity, and a Super Admin account too.
    $samePersonSa = Make::account($other, 'SUPERVISOR', ['person' => $this->maker->person]);
    expect($this->service->canDecide($samePersonSa, $request))->toBeFalse()
        ->and(fn () => $this->service->approve($request, $samePersonSa))->toThrow(ApprovalDenied::class);
});

it('requires approval.decide for the checker', function () {
    $exec = Make::account($this->entity, 'EXECUTIVE');
    as_($this->maker);
    $request = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);

    expect(fn () => $this->service->approve($request, $exec))->toThrow(ApprovalDenied::class, 'approval.decide');
});

it('requires the maker permission to submit', function () {
    $exec = Make::account($this->entity, 'EXECUTIVE');
    as_($exec);

    expect(fn () => requestGateChange($this->service, $this->gateRole, []))->toThrow(ApprovalDenied::class, 'role.manage');
});

it('blocks granting permissions the maker does not hold', function () {
    as_($this->owner);

    // Owners do not hold entity.manage.
    expect(fn () => requestGateChange($this->service, $this->gateRole, ['entity.manage']))
        ->toThrow(ValidationException::class);
});

it('keeps the Super Admin role out of reach of entity users', function () {
    $saRole = Make::system(fn () => Role::query()->whereNull('entity_id')->where('code', 'OWNER')->firstOrFail());
    as_($this->owner);

    expect(fn () => $this->service->submit(ChangeRolePermissions::KEY, ['role_id' => $saRole->id, 'permissions' => []], 'x'))
        ->toThrow(ValidationException::class);
});

it('can be rejected with a reason, and then cannot be approved', function () {
    as_($this->maker);
    $request = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);
    as_($this->owner2);
    $rejected = $this->service->reject($request, $this->owner2, 'Not needed');

    expect($rejected->status)->toBe('rejected')
        ->and(fn () => $this->service->approve($rejected, $this->owner2))->toThrow(ApprovalDenied::class, 'no longer pending')
        ->and($this->gateRole->fresh()->permissionCodes())->not->toContain('lab.test');
});

it('expires', function () {
    as_($this->maker);
    $request = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);
    $this->travel(73)->hours();
    as_($this->owner2);

    expect(fn () => $this->service->approve($request, $this->owner2))->toThrow(ApprovalDenied::class);
});

it('refuses to apply a request made against an outdated role', function () {
    as_($this->maker);
    $first = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);
    $second = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'stock.view']);

    as_($this->owner2);
    $this->service->approve($first, $this->owner2);
    $result = $this->service->approve($second, $this->owner2);

    expect($result->status)->toBe('failed')
        ->and($result->error)->toContain('changed after this request');
});

it('lets the maker withdraw a pending request', function () {
    as_($this->maker);
    $request = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);

    expect($this->service->cancel($request, $this->maker)->status)->toBe('cancelled')
        ->and(fn () => $this->service->cancel($request->fresh(), $this->owner2))->toThrow(ApprovalDenied::class);
});

it('audits every step', function () {
    as_($this->maker);
    $request = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);
    as_($this->owner2);
    $this->service->approve($request, $this->owner2);

    $actions = AuditLog::query()->where('subject_id', $request->id)->orderBy('seq')->pluck('action')->all();
    expect($actions)->toContain('approval.submitted', 'approval.approved', 'approval.executed');
});

it('applies a request of the Owner or the mill\'s Super Admin at once, without a second person', function () {
    as_($this->owner);
    $done = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->permissionCodes(), 'lab.test']);

    expect($done->status)->toBe('executed')
        ->and($done->checker_person_id)->toBe($this->owner->person_id)
        ->and($done->decision_note)->toContain('no second approval needed')
        ->and($this->gateRole->fresh()->permissionCodes())->toContain('lab.test');

    // A Super Admin may also decide a pending request they raised earlier as staff… or any staff request.
    as_($this->maker);
    $pending = requestGateChange($this->service, $this->gateRole, [...$this->gateRole->fresh()->permissionCodes(), 'stock.view']);
    expect($this->service->canDecide($this->owner, $pending))->toBeTrue();
});
