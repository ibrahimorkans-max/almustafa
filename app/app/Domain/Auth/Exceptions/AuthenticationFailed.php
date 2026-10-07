<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

/**
 * A login/refresh was refused. `reason` is precise and goes to the audit log;
 * the client only ever sees `publicCode` so usernames and lock state are not disclosed.
 */
final class AuthenticationFailed extends RuntimeException
{
    public const INVALID_CREDENTIALS = 'invalid_credentials';

    public const INVALID_MFA_CODE = 'invalid_mfa_code';

    public const DEVICE_NOT_ALLOWED = 'device_not_allowed';

    public const DEVICE_PENDING_APPROVAL = 'device_pending_approval';

    public const NETWORK_NOT_ALLOWED = 'network_not_allowed';

    public const SESSION_INVALID = 'session_invalid';

    public function __construct(
        public readonly string $publicCode,
        public readonly string $reason,
        public readonly int $status = 401,
    ) {
        parent::__construct($publicCode);
    }

    public static function credentials(string $reason): self
    {
        return new self(self::INVALID_CREDENTIALS, $reason);
    }

    public function publicMessage(): string
    {
        return match ($this->publicCode) {
            self::INVALID_MFA_CODE => 'The authentication code is not valid.',
            self::DEVICE_NOT_ALLOWED => 'This device is not allowed to sign in.',
            self::DEVICE_PENDING_APPROVAL => 'This device needs approval by another Super Admin before it can be used.',
            self::NETWORK_NOT_ALLOWED => 'Sign-in is not allowed from this network.',
            self::SESSION_INVALID => 'Your session has ended. Please sign in again.',
            default => 'These credentials do not match our records, or the account is locked.',
        };
    }
}
