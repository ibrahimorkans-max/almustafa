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
        // Key for searchable hashes of encrypted identifiers (GSTIN, PAN, bank account). Empty = derived from APP_KEY.
        'blind_index_key' => env('RICEMILL_BLIND_INDEX_KEY', ''),
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
        // Payments above this need a second, different approver (paise; default ₹5,00,000).
        'second_approval_above_paise' => (int) env('RICEMILL_SECOND_APPROVAL_ABOVE_PAISE', 500_000_00),
        // Income-tax s. 40A(3): cash paid to one person in one day above this is not allowed (paise).
        'cash_limit_per_day_paise' => (int) env('RICEMILL_CASH_LIMIT_PER_DAY_PAISE', 10_000_00),
    ],

    'gst' => [
        // E-invoicing applies once the mill's turnover crosses the notified threshold.
        'einvoice_enabled' => (bool) env('RICEMILL_EINVOICE_ENABLED', false),
        'provider' => env('RICEMILL_EINVOICE_PROVIDER', 'sandbox'),     // sandbox | <gsp adapter>
        'eway_threshold_paise' => (int) env('RICEMILL_EWAY_THRESHOLD_PAISE', 50_000_00),
        // Refuse the gate pass for a consignment above the threshold without an e-way bill.
        'require_eway_for_gate_pass' => (bool) env('RICEMILL_REQUIRE_EWAY_FOR_GATE_PASS', false),
        // TDS on purchases, s. 194Q (only if last year's turnover exceeded ₹10 crore).
        'tds_194q_enabled' => (bool) env('RICEMILL_TDS_194Q_ENABLED', false),
        'tds_194q_threshold_paise' => (int) env('RICEMILL_TDS_194Q_THRESHOLD_PAISE', 50_00_000_00),
        'tds_194q_rate_bp' => 10,          // 0.1 %
        'tds_194q_no_pan_rate_bp' => 500,  // 5 % (s. 206AA)
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

    'archive' => [
        // Closed records (saudas, gate entries, batches, requisitions, claims) are archived this long after closing.
        'retention_months' => (int) env('RICEMILL_ARCHIVE_RETENTION_MONTHS', 24),
    ],

    'approvals' => [
        'expire_after_hours' => (int) env('RICEMILL_APPROVAL_EXPIRY_HOURS', 72),
    ],

];
