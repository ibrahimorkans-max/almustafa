<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Issued documents are seen by staff who generate them; templates by those who manage them. Neither
 * is edited or deleted on a screen: documents are corrected by revision, templates by new versions.
 */
class DocumentPolicy
{
    protected string $permission = 'hr.documents.generate';

    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission($this->permission);
    }

    public function view(LoginAccount $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(LoginAccount $user): bool
    {
        return $this->permission === 'hr.documents.template.manage' && $user->hasPermission($this->permission);
    }

    public function update(LoginAccount $user, Model $record): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}
