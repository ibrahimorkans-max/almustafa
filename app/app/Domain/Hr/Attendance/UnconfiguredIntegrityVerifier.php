<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

/** Default until Google / Apple verification is configured: every token is "unverified". */
final class UnconfiguredIntegrityVerifier implements IntegrityVerifier
{
    public function verify(string $platform, string $token): string
    {
        return self::UNVERIFIED;
    }
}
