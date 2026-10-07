<?php

return [

    'auth' => [
        // Consecutive failed logins (password or 2FA) before the account is locked.
        'max_failed_attempts' => (int) env('RICEMILL_MAX_FAILED_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('RICEMILL_LOCKOUT_MINUTES', 15),

        // API bearer tokens (minutes).
        'access_token_ttl' => (int) env('RICEMILL_ACCESS_TOKEN_TTL', 15),
        'super_admin_access_token_ttl' => (int) env('RICEMILL_SA_ACCESS_TOKEN_TTL', 5),
        'refresh_token_ttl' => (int) env('RICEMILL_REFRESH_TOKEN_TTL', 60 * 24),

        // Idle timeout for web sessions (minutes); the absolute limit is the role's session_max_minutes.
        'web_idle_minutes' => (int) env('RICEMILL_WEB_IDLE_MINUTES', 30),

        'recovery_codes' => 10,
        'password_min_length' => 12,
        'mfa_issuer' => env('RICEMILL_MFA_ISSUER', env('APP_NAME', 'Rice Mill ERP')),
    ],

    'security' => [
        // Super Admin may only sign in from an allow-listed IP (production: true).
        'super_admin_require_ip_allowlist' => (bool) env('RICEMILL_SA_REQUIRE_IP_ALLOWLIST', false),
        // Super Admin may only sign in from a device approved by another Super Admin.
        'super_admin_require_trusted_device' => (bool) env('RICEMILL_SA_REQUIRE_TRUSTED_DEVICE', true),
        // First-ever Super Admin device is trusted automatically (installation only; set false afterwards).
        'bootstrap_trust_first_device' => (bool) env('RICEMILL_BOOTSTRAP_TRUST_FIRST_DEVICE', false),
        'hsts' => (bool) env('RICEMILL_HSTS', true),
    ],

    'approvals' => [
        'expire_after_hours' => (int) env('RICEMILL_APPROVAL_EXPIRY_HOURS', 72),
    ],

];
