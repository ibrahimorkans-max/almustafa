<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuthSession */
final class SessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $current = $request->attributes->get('auth_session');

        return [
            'id' => $this->id,
            'login_account_id' => $this->login_account_id,
            'channel' => $this->channel,
            'device' => $this->device ? [
                'id' => $this->device->id, 'name' => $this->device->name, 'platform' => $this->device->platform,
            ] : null,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,
            'is_current' => $current instanceof AuthSession && $current->id === $this->id,
        ];
    }
}
