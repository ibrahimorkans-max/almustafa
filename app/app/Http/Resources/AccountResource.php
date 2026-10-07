<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LoginAccount */
final class AccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'person' => ['id' => $this->person_id, 'full_name' => $this->person?->full_name],
            'entity' => ['id' => $this->entity_id, 'code' => $this->entity?->code, 'name' => $this->entity?->name],
            'working_entity_id' => app(TenantContext::class)->entityId(),
            'role' => ['code' => $this->role->code, 'name' => $this->role->name, 'is_super_admin' => $this->role->is_super_admin],
            'mfa_enabled' => $this->hasMfa(),
            'permissions' => $this->hasPendingSecuritySetup() ? [] : $this->permissionCodes(),
        ];
    }
}
