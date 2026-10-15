<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Contracts\ExplainsCheckerRule;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Audit\AuditLogger;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replace a role's permission set.
 *
 * Privilege-escalation guards:
 *  - the global Owner role can only be changed with an Owner as checker;
 *  - a non-Super-Admin maker or checker can only grant permissions they hold themselves.
 */
final class ChangeRolePermissions implements ApprovalAction, ExplainsCheckerRule
{
    public const KEY = 'role.permissions.change';

    public function __construct(private readonly AuditLogger $audit) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'role.manage';
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $role = Role::query()->find($payload['role_id'] ?? null);
        if ($role === null) {
            throw ValidationException::withMessages(['role_id' => 'Role not found.']);
        }
        if ($role->is_super_admin && ! $maker->isOwner()) {
            throw ValidationException::withMessages(['role_id' => 'Only an Owner can request changes to the Owner role.']);
        }

        $permissions = array_values(array_unique(array_map('strval', (array) ($payload['permissions'] ?? []))));
        sort($permissions);
        $unknown = array_diff($permissions, PermissionCatalog::codes());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permissions: '.implode(', ', $unknown)]);
        }

        $escalation = $this->beyondOwnGrants($maker, $role, $permissions);
        if ($escalation !== []) {
            throw ValidationException::withMessages(['permissions' => 'You cannot grant permissions you do not hold: '.implode(', ', $escalation)]);
        }

        return [
            'role_id' => $role->id,
            'role_code' => $role->code,
            'before' => $role->permissionCodes(),
            'permissions' => $permissions,
        ];
    }

    public function whyCheckerMayNotDecide(LoginAccount $checker, ApprovalRequest $request): string
    {
        $role = Role::query()->find($request->payload['role_id'] ?? null);
        if ($role === null) {
            return 'The role in this request no longer exists.';
        }
        if ($role->is_super_admin && ! $checker->isOwner()) {
            return 'Only another Owner can decide changes to the Owner role.';
        }

        return 'You can only grant permissions you hold yourself: '
            .implode(', ', $this->beyondOwnGrants($checker, $role, (array) ($request->payload['permissions'] ?? []))).'.';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        $role = Role::query()->find($request->payload['role_id'] ?? null);
        if ($role === null) {
            return false;
        }
        if ($role->is_super_admin && ! $checker->isOwner()) {
            return false;
        }

        return $this->beyondOwnGrants($checker, $role, (array) ($request->payload['permissions'] ?? [])) === [];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        /** @var Role $role */
        $role = Role::query()->lockForUpdate()->find($request->payload['role_id'])
            ?? throw new ApprovalDenied('Role no longer exists.');

        $before = $role->permissionCodes();
        if ($before !== $request->payload['before']) {
            throw new ApprovalDenied('The role was changed after this request was made; submit a new request.');
        }

        $after = (array) $request->payload['permissions'];
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($removed !== []) {
            DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission_code', $removed)->delete();
        }
        if ($added !== []) {
            DB::table('role_permissions')->insert(array_map(fn (string $code) => [
                'role_id' => $role->id,
                'permission_code' => $code,
                'entity_id' => $role->entity_id,
                'granted_by' => $checker->id,
                'created_at' => now(),
            ], $added));
        }

        $role->flushPermissionCache();
        $this->audit->record('role.permissions.changed', $role, ['permissions' => $before], ['permissions' => $after], [
            'approval_request_id' => $request->id, 'added' => $added, 'removed' => $removed,
        ]);

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @param  list<string>  $requested
     * @return list<string> permissions newly granted that $account does not hold
     */
    private function beyondOwnGrants(LoginAccount $account, Role $role, array $requested): array
    {
        if ($account->isOwner()) {
            return [];
        }

        $newlyGranted = array_diff($requested, $role->permissionCodes());

        return array_values(array_diff($newlyGranted, $account->permissionCodes()));
    }
}
