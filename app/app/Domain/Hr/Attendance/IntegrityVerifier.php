<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

/**
 * Checks a Play Integrity verdict token (Android) or an App Attest assertion (iOS) with Google /
 * Apple. Needs the mill's own API credentials; until an implementation is bound, tokens are
 * reported as unverified — never as passed.
 */
interface IntegrityVerifier
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const UNVERIFIED = 'unverified';

    /** @return self::PASSED|self::FAILED|self::UNVERIFIED */
    public function verify(string $platform, string $token): string;
}
