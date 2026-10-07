<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\AuthSession;
use App\Models\LoginAccount;

/**
 * Who is acting in the current request or job, for auditing and `created_by`.
 * Filled by the web/API authentication layers; empty for console/system work.
 */
final class ActorContext
{
    private ?LoginAccount $account = null;

    private ?AuthSession $session = null;

    private ?string $deviceId = null;

    private ?string $ip = null;

    private ?string $userAgent = null;

    public function set(LoginAccount $account, ?AuthSession $session = null, ?string $deviceId = null): void
    {
        $this->account = $account;
        $this->session = $session;
        $this->deviceId = $deviceId ?? $session?->device_id;
    }

    public function setClient(?string $ip, ?string $userAgent): void
    {
        $this->ip = $ip;
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 512) : null;
    }

    public function clear(): void
    {
        $this->account = null;
        $this->session = null;
        $this->deviceId = null;
    }

    public function account(): ?LoginAccount
    {
        return $this->account;
    }

    public function accountId(): ?string
    {
        return $this->account?->getKey();
    }

    public function personId(): ?string
    {
        return $this->account?->person_id;
    }

    public function session(): ?AuthSession
    {
        return $this->session;
    }

    public function deviceId(): ?string
    {
        return $this->deviceId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function isSuperAdmin(): bool
    {
        return $this->account?->isSuperAdmin() ?? false;
    }
}
