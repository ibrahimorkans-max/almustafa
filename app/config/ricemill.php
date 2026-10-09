<?php

return [

    // Business identity printed on screens, slips, invoices and reports. The mill entity's own address,
    // phone, NTN and STRN (Entities) take precedence on documents; these are the fall-backs and the brand.
    'brand' => [
        'name' => env('RICEMILL_BRAND_NAME', 'Al Mustafa Rice Mills'),
        'name_ur' => env('RICEMILL_BRAND_NAME_UR', 'المصطفیٰ رائس ملز'),
        'address' => env('RICEMILL_BRAND_ADDRESS', 'Adda Permat, 1 km Shujabad Road, Lodhran, Punjab'),
        'phone' => env('RICEMILL_BRAND_PHONE', '0300-6834588'),
        'logo_wide' => 'images/brand/logo-wide.png',
        'logo_wide_dark' => 'images/brand/logo-wide-dark.png',
        'logo_mark' => 'images/brand/logo-mark.png',
        'emblem' => 'images/brand/emblem.png',
        'favicon' => 'images/brand/favicon-32.png',
        'green' => '#0B4F2C',
        'gold' => '#D99A0E',
    ],

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
        'mfa_issuer' => env('RICEMILL_MFA_ISSUER', env('APP_NAME', 'Al Mustafa Rice Mills')),
    ],

    'security' => [
        // Super Admin may only sign in from an allow-listed IP (production: true).
        'super_admin_require_ip_allowlist' => (bool) env('RICEMILL_SA_REQUIRE_IP_ALLOWLIST', false),
        // Super Admin may only sign in from a device approved by another Super Admin.
        'super_admin_require_trusted_device' => (bool) env('RICEMILL_SA_REQUIRE_TRUSTED_DEVICE', true),
        // First-ever Super Admin device is trusted automatically (installation only; set false afterwards).
        'bootstrap_trust_first_device' => (bool) env('RICEMILL_BOOTSTRAP_TRUST_FIRST_DEVICE', false),
        'hsts' => (bool) env('RICEMILL_HSTS', true),
        // Key for searchable hashes of encrypted identifiers (NTN, CNIC, STRN, IBAN). Empty = derived from APP_KEY.
        'blind_index_key' => env('RICEMILL_BLIND_INDEX_KEY', ''),
        // Who else hears about lockdowns, duress sign-ins and lost devices (comma-separated).
        'contact_emails' => env('RICEMILL_SECURITY_CONTACT_EMAILS', ''),
        'contact_sms' => env('RICEMILL_SECURITY_CONTACT_SMS', ''),
        'sms_log_channel' => env('RICEMILL_SECURITY_SMS_LOG_CHANNEL', 'stack'),
        // Roles that may set a duress PIN.
        'duress_roles' => ['SUPER_ADMIN', 'OWNER', 'ACCOUNTANT'],
        // Failed sign-ins: progressive delay (seconds) from this many failures on; after this many
        // temporary lockouts within 24 h the account stays locked until an administrator unlocks it.
        'progressive_delay_after' => 3,
        'max_progressive_delay_seconds' => 8,
        'lockouts_before_admin_unlock' => 3,
        // Access review: accounts without a sign-in for this long are "dormant".
        'dormant_days' => (int) env('RICEMILL_DORMANT_DAYS', 30),
    ],

    'sync' => [
        // A device offline longer than this must sign in again; older events go to supervisor review.
        'max_offline_hours' => (int) env('RICEMILL_SYNC_MAX_OFFLINE_HOURS', 72),
        'max_push_events' => 500,
        'max_pull_changes' => 500,
        // Accepted clock skew between a device and the server.
        'max_future_skew_seconds' => 300,
    ],

    'gate' => [
        // Evidence the indicator must send with a weight: this many consecutive samples within one scale step.
        'min_stable_samples' => (int) env('RICEMILL_MIN_STABLE_SAMPLES', 5),
        // Repeat-tare check: flag a tare that differs from the vehicle's recent median by more than
        // max(tolerance kg, tolerance % of median).
        'tare_tolerance_kg' => env('RICEMILL_TARE_TOLERANCE_KG', '100'),
        'tare_tolerance_bp' => (int) env('RICEMILL_TARE_TOLERANCE_BP', 200),
        'tare_history' => 5,
        // Refuse (instead of flag) weighments on a scale without a valid stamping certificate.
        'block_uncertified_scale' => (bool) env('RICEMILL_BLOCK_UNCERTIFIED_SCALE', false),
        'certificate_reminder_days' => [30, 7, 0],
    ],

    'inventory' => [
        // Batch closed with (input − outputs) above this share of input → "unaccounted loss" alert.
        'unaccounted_loss_bp' => (int) env('RICEMILL_UNACCOUNTED_LOSS_BP', 150),
        // Stock count lower than the system balance by more than this share → "shrinkage" alert.
        'shrinkage_bp' => (int) env('RICEMILL_SHRINKAGE_BP', 50),
    ],

    'accounts' => [
        // Round the amount owed on each purchase bill to the rupee (difference to "Round off").
        'round_bills_to_rupee' => (bool) env('RICEMILL_ROUND_BILLS_TO_RUPEE', true),
        // Payments above this need a second, different approver (paisa; default Rs 500,000).
        'second_approval_above_paise' => (int) env('RICEMILL_SECOND_APPROVAL_ABOVE_PAISE', 500_000_00),
        // Income Tax Ordinance 2001, s. 21(l): an expense paid in cash above Rs 250,000 is not deductible,
        // so cash to one person in one day is refused above this (paisa). Pay through the bank instead.
        'cash_limit_per_day_paise' => (int) env('RICEMILL_CASH_LIMIT_PER_DAY_PAISE', 250_000_00),
    ],

    // Pakistan tax settings. The rates change with each Finance Act: the mill's tax adviser must confirm
    // them before use. Sales tax applies only when the mill's STRN is filled in (Entities).
    'tax' => [
        // Further tax (Sales Tax Act 1990, s. 3(1A)) on taxable supplies to a buyer without an STRN.
        'further_tax_enabled' => (bool) env('RICEMILL_FURTHER_TAX_ENABLED', true),
        'further_tax_rate_bp' => (int) env('RICEMILL_FURTHER_TAX_RATE_BP', 400),
        // Withholding income tax on payments for goods (Income Tax Ordinance 2001, s. 153(1)(a)), deducted from
        // the supplier's bill when the mill is a withholding agent. Non-filers (not on the Active Taxpayer List)
        // suffer the higher rate. No deduction while a supplier's payments in the tax year stay under the threshold.
        'wht_enabled' => (bool) env('RICEMILL_WHT_ENABLED', false),
        'wht_threshold_paise' => (int) env('RICEMILL_WHT_THRESHOLD_PAISE', 75_000_00),
        'wht_rate_filer_bp' => (int) env('RICEMILL_WHT_RATE_FILER_BP', 550),
        'wht_rate_non_filer_bp' => (int) env('RICEMILL_WHT_RATE_NON_FILER_BP', 1100),
    ],

    'anomalies' => [
        'net_weight_z' => 3.5,             // robust z-score vs the vehicle's last trips
        'min_trips' => 5,
        'rate_deviation_bp' => 1500,       // purchase rate vs 30-day median
        'working_hours' => ['06:00', '22:00'],
        'plate_mismatch_repeat' => 2,      // same vehicle within 30 days
        'manual_override_repeat' => 3,     // per user within 30 days
        'approvals_same_party' => 5,       // per approver and party within 7 days
        'yield_drift_batches' => 3,        // consecutive out-of-range batches of a variety
    ],

    'ops' => [
        'scheduler_max_minutes' => 5,
        'queue_max_minutes' => 20,
        'backup_max_hours' => 26,
        'min_free_disk_percent' => 10,
        // Failed logins across the system within 15 minutes that trigger an alert to Super Admins.
        'failed_login_burst' => (int) env('RICEMILL_FAILED_LOGIN_BURST', 30),
    ],

    'backup' => [
        // 32-byte key, base64. Keep it in the secret store — never inside a backup.
        'key' => env('RICEMILL_BACKUP_KEY', ''),
        'path' => env('RICEMILL_BACKUP_PATH', storage_path('backups')),
        // Laravel filesystem disk for the off-site copy (an S3-compatible bucket); empty = local only.
        'offsite_disk' => env('RICEMILL_BACKUP_OFFSITE_DISK', ''),
        'keep_daily' => 14,
        'keep_weekly' => 8,
        'keep_monthly' => 12,
        // Who receives the daily audit-chain anchor (head hashes); empty = only kept in the backup manifest.
        'anchor_email' => env('RICEMILL_AUDIT_ANCHOR_EMAIL', ''),
        // Restore drill target: a separate, empty database the app login may own (never the live one).
        'drill_database' => env('RICEMILL_BACKUP_DRILL_DATABASE', ''),
        'pg_bin' => env('RICEMILL_PG_BIN', ''),        // folder of pg_dump / pg_restore / psql when not on PATH
        'mysql_bin' => env('RICEMILL_MYSQL_BIN', ''),
    ],

    'attendance' => [
        // The notice employees accept before their first punch. Change the version when the text changes:
        // everyone is asked again.
        'notice_version' => '2026-10',
        'notice' => 'When you mark attendance, this app records your location at that moment and a photo taken with the '
            .'front camera, together with the phone and time. They are used only to confirm your attendance and are '
            .'seen only by HR. Your location is not tracked at any other time.',
        'require_selfie' => (bool) env('RICEMILL_ATTENDANCE_REQUIRE_SELFIE', true),
        'max_accuracy_m' => (int) env('RICEMILL_ATTENDANCE_MAX_ACCURACY_M', 100),
        // Device time vs time reconstructed from the monotonic clock.
        'clock_tolerance_seconds' => 120,
        // An offline punch uploaded later than this is flagged for review.
        'late_upload_hours' => 48,
        // HR phase 3: integrity and sanity checks.
        // true once staff use the native app: a punch without integrity signals is flagged (the PWA cannot send them).
        'require_integrity' => (bool) env('RICEMILL_ATTENDANCE_REQUIRE_INTEGRITY', false),
        'max_speed_kmh' => (int) env('RICEMILL_ATTENDANCE_MAX_SPEED_KMH', 100),
        'repeated_coordinates_days' => 30,
        'shared_device_hours' => 12,
        'device_clock_off_seconds' => 300,
        'suspicious_accuracy_m' => 1,
        // Alert when one employee has this many fake-location rejections / integrity flags within 7 days.
        'alert_mock_rejections' => 3,
        'alert_integrity_flags' => 5,
    ],

    'archive' => [
        // Closed records (saudas, gate entries, batches, requisitions, claims) are archived this long after closing.
        'retention_months' => (int) env('RICEMILL_ARCHIVE_RETENTION_MONTHS', 24),
    ],

    'approvals' => [
        'expire_after_hours' => (int) env('RICEMILL_APPROVAL_EXPIRY_HOURS', 72),
    ],

];
