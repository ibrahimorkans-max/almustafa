<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Carbon\CarbonInterface;

final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public CarbonInterface $accessExpiresAt,
        public string $refreshToken,
        public CarbonInterface $refreshExpiresAt,
        public string $sessionId,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $this->accessToken,
            'access_token_expires_at' => $this->accessExpiresAt->toIso8601String(),
            'refresh_token' => $this->refreshToken,
            'refresh_token_expires_at' => $this->refreshExpiresAt->toIso8601String(),
            'session_id' => $this->sessionId,
        ];
    }
}
