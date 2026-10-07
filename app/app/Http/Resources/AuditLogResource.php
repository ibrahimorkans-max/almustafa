<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
final class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chain' => $this->chain,
            'seq' => $this->seq,
            'entity_id' => $this->entity_id,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString('microsecond'),
            'actor_login_account_id' => $this->actor_login_account_id,
            'actor_person_id' => $this->actor_person_id,
            'acting_as_super_admin' => $this->acting_as_super_admin,
            'device_id' => $this->device_id,
            'ip' => $this->ip,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'meta' => $this->meta,
            'hash' => $this->hash,
        ];
    }
}
