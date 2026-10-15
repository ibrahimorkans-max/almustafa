<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Entity;
use App\Models\LoginAccount;

final class EntityPolicy
{
    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('entity.view');
    }

    public function view(LoginAccount $user, Entity $entity): bool
    {
        return $user->hasPermission('entity.view');
    }

    public function create(LoginAccount $user): bool
    {
        return $user->isOwner() && $user->hasPermission('entity.manage');
    }

    /** All firms with entity.manage (Owner); the own mill only with entity.edit_own (Super Admin). */
    public function update(LoginAccount $user, Entity $entity): bool
    {
        return $user->hasPermission('entity.manage')
            || ($user->hasPermission('entity.edit_own') && $entity->id === $user->entity_id);
    }

    /** Entities are never deleted (legal retention); suspend instead. */
    public function delete(LoginAccount $user, Entity $entity): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}
