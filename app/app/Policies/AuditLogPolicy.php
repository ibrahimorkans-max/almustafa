<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\LoginAccount;

/** No role, including Owner, can change or delete audit rows. */
final class AuditLogPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('audit.view');
    }

    public function view(LoginAccount $user, AuditLog $log): bool
    {
        return $user->hasPermission('audit.view');
    }

    public function create(LoginAccount $user): bool
    {
        return false;
    }

    public function update(LoginAccount $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, AuditLog $log): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }

    public function forceDelete(LoginAccount $user, AuditLog $log): bool
    {
        return false;
    }
}
