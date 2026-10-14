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

    public const ACCESS_DISABLED = 'access_disabled';

    public const SYSTEM_LOCKED = 'system_locked';

    /** Neutral ending (used for the duress PIN): says nothing about why. */
    public const SIGNED_OUT = 'signed_out';

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
            self::DEVICE_PENDING_APPROVAL => 'This device is new to your account. A request to approve it has been sent; sign in again once the owner (or, for a Super Admin, another Super Admin) has approved it.',
            self::NETWORK_NOT_ALLOWED => 'Sign-in is not allowed from this network.',
            self::SESSION_INVALID => 'Your session has ended. Please sign in again.',
            self::ACCESS_DISABLED => 'Access disabled, contact your administrator.',
            self::SYSTEM_LOCKED => 'System locked by administrator.',
            self::SIGNED_OUT => 'Signed out. Please try again later.',
            default => 'These credentials do not match our records, or the account is locked.',
        };
    }
}
