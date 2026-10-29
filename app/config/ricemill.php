<?php

return [

    // The product itself (MillNexa): the app's sign-in, menu, browser tab, sign-up page, error and
    // maintenance pages, the public document check, and the invoices the product owner sends customers.
    'product' => [
        'name' => env('PRODUCT_NAME', 'MillNexa'),
        'tagline' => env('PRODUCT_TAGLINE', 'Smart Rice Mill Management'),
        'logo' => 'images/product/millnexa-logo.png',               // mark and name side by side (menu, sign-in)
        'logo_dark' => 'images/product/millnexa-logo-dark.png',
        'logo_stacked' => 'images/product/millnexa-logo-stacked.png', // mark above the name and tagline
        'logo_stacked_dark' => 'images/product/millnexa-logo-stacked-dark.png',
        'logo_print' => 'images/product/millnexa-logo-print.jpg',    // on white, for PDFs
        'mark' => 'images/product/millnexa-mark.png',
        'favicon' => 'images/product/favicon-32.png',
        'apple_touch' => 'images/product/apple-touch-icon.png',
        'icon_192' => 'images/product/icon-192.png',
        'icon_512' => 'images/product/icon-512.png',
        'green' => '#217D25',      // the leaf
        'green_bright' => '#308C09', // "Nexa"
        'gold' => '#F2B705',       // the ear of rice
        'ink' => '#192327',        // "Mill"
    ],

    // A mill's identity on its own slips, invoices and reports: the first installation's (Al Mustafa Rice
    // Mills). Each mill's own details (Print & branding) take precedence; these are the fall-backs.
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

    /*
     * Messages to parties (login details, money entries, vehicles). With no gateway address, each message
     * waits in Masters → Party messages and the office sends it by hand (a WhatsApp link with the text).
     * A gateway gets a JSON POST {to, text, sender} with "Authorization: Bearer <token>".
     */
    'messaging' => [
        'sms_url' => env('RICEMILL_SMS_URL', ''),
        'sms_token' => env('RICEMILL_SMS_TOKEN', ''),
        'whatsapp_url' => env('RICEMILL_WHATSAPP_URL', ''),
        'whatsapp_token' => env('RICEMILL_WHATSAPP_TOKEN', ''),
        'sender' => env('RICEMILL_MESSAGE_SENDER', 'AlMustafa'),
        // Address of the party sign-in page given in the login message.
        'portal_url' => env('RICEMILL_PORTAL_URL', ''),

        // WhatsApp Business Cloud API (Meta). Messages the mill starts must use an approved template (category
        // Utility) with one variable inside fixed words: "*Al Mustafa Rice Mills*", a new line, "{{1}}", a new line,
        // "Thank you for working with us." (see docs/party-accounts-and-messages.md).
        'whatsapp_cloud' => [
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),
            'access_token' => env('WHATSAPP_ACCESS_TOKEN', ''),
            'template' => env('WHATSAPP_TEMPLATE', 'mill_update'),
            'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
            'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        ],

        // Firebase Cloud Messaging: notifications on the phones and browsers parties turned them on for.
        'firebase' => [
            // The service account key (JSON from Firebase → Project settings → Service accounts), kept outside public/.
            'credentials' => env('FIREBASE_CREDENTIALS', ''),
            // The web app (Project settings → General → Your apps) and the Web Push key (Cloud Messaging).
            'web' => [
                'apiKey' => env('FIREBASE_API_KEY', ''),
                'authDomain' => env('FIREBASE_AUTH_DOMAIN', ''),
                'projectId' => env('FIREBASE_PROJECT_ID', ''),
                'messagingSenderId' => env('FIREBASE_MESSAGING_SENDER_ID', ''),
                'appId' => env('FIREBASE_APP_ID', ''),
            ],
            'vapid_key' => env('FIREBASE_VAPID_KEY', ''),
        ],
    ],

    'security' => [
        // The reverse proxy / load balancer in front of the app, if any (comma-separated IPs or CIDR ranges,
        // e.g. "10.0.0.5" or "10.0.0.0/24"). Only these may say who the visitor is (X-Forwarded-For), so the
        // IP allow-list, per-address lock-outs and rate limits see the real visitor. Empty: no proxy is
        // trusted (right when PHP runs behind nginx/Apache on the same server). Never "*".
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
            fn (string $p) => $p !== '' && $p !== '*' && $p !== '**')),

        // Owner may only sign in from an allow-listed IP (production: true).
        'super_admin_require_ip_allowlist' => (bool) env('RICEMILL_SA_REQUIRE_IP_ALLOWLIST', false),
        // Owner may only sign in from a device approved by another Owner.
        'super_admin_require_trusted_device' => (bool) env('RICEMILL_SA_REQUIRE_TRUSTED_DEVICE', true),
        // First-ever Owner device is trusted automatically (installation only; set false afterwards).
        'bootstrap_trust_first_device' => (bool) env('RICEMILL_BOOTSTRAP_TRUST_FIRST_DEVICE', false),
        // Which accounts may sign in only from an approved device, until a Super Admin changes it under
        // Security → Sign-in rules: everyone | selected (accounts switched on one by one) | off.
        'trusted_devices_default' => env('RICEMILL_TRUSTED_DEVICES', 'off'),
        // Roles left out of "everyone": employees punch from a phone HR already approves (attendance devices).
        'trusted_devices_exempt_roles' => ['EMPLOYEE', 'PARTY'],   // they see only their own records
        'hsts' => (bool) env('RICEMILL_HSTS', true),
        // Discourage screenshots and recordings in the browser: watermark with the user's name, IP and
        // time, page hidden while the window is not in focus, printing blocked (public/js/screen-guard.js).
        // Only the starting value: the Owner and each mill's Super Admin switch it under Security → Screen protection.
        'screen_guard' => (bool) env('RICEMILL_SCREEN_GUARD', true),
        // Key for searchable hashes of encrypted identifiers (NTN, CNIC, STRN, IBAN). Empty = derived from APP_KEY.
        'blind_index_key' => env('RICEMILL_BLIND_INDEX_KEY', ''),
        // Who else hears about lockdowns, duress sign-ins and lost devices (comma-separated).
        'contact_emails' => env('RICEMILL_SECURITY_CONTACT_EMAILS', ''),
        'contact_sms' => env('RICEMILL_SECURITY_CONTACT_SMS', ''),
        'sms_log_channel' => env('RICEMILL_SECURITY_SMS_LOG_CHANNEL', 'stack'),
        // Roles that may set a duress PIN.
        'duress_roles' => ['OWNER', 'SUPER_ADMIN', 'ACCOUNTANT'],
        // Failed sign-ins: progressive delay (seconds) from this many failures on; after this many
        // temporary lockouts within 24 h the account stays locked until an administrator unlocks it.
        'progressive_delay_after' => 3,
        'max_progressive_delay_seconds' => 8,
        'lockouts_before_admin_unlock' => 3,
        // Wrong sign-ins lock out the ADDRESS they come from (max_failed_attempts per address). The whole
        // account is locked only after this many failures from any mix of addresses since the last good
        // sign-in, so a stranger cannot lock out the real person (see LoginService::registerFailure).
        'account_lock_after' => (int) env('RICEMILL_ACCOUNT_LOCK_AFTER', 30),
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

    // HR documents engine (payslips, vouchers, certificates).
    'documents' => [
        // pdftoppm (poppler-utils) turns a PDF into a PNG for quick sharing; found on the PATH when empty.
        'pdftoppm' => env('RICEMILL_PDFTOPPM'),
    ],

    // Where sign-ins come from: DB-IP "IP to City Lite" (CC BY 4.0), downloaded monthly by geoip:update.
    'geoip' => [
        'path' => env('RICEMILL_GEOIP_PATH', storage_path('app/geoip/dbip-city-lite.mmdb')),
        // %s = year-month (2026-10); the file is published at the start of each month.
        'url' => env('RICEMILL_GEOIP_URL', 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz'),
    ],

    // Exact place of a sign-in from the browser's position (area, city, region, country). "nominatim" asks
    // OpenStreetMap (internet) or the mill's own Nominatim server at RICEMILL_GEOCODER_URL; "off" uses only
    // the mill's sites (offline). Without either, the coordinates and the IP's city are shown.
    'geocoder' => [
        'driver' => env('RICEMILL_GEOCODER', 'nominatim'),
        'url' => env('RICEMILL_GEOCODER_URL', 'https://nominatim.openstreetmap.org/reverse'),
        'email' => env('RICEMILL_GEOCODER_EMAIL'),
        'user_agent' => env('RICEMILL_GEOCODER_USER_AGENT', 'AlMustafaRiceMills-ERP/1.0'),
        'timeout' => (int) env('RICEMILL_GEOCODER_TIMEOUT', 4),
    ],

    // Live weight on screen: the gate agent sends the indicator reading about once a second; a reading
    // older than this many seconds is shown as "no signal".
    // Mill server: the ERP runs on a PC inside the mill and is reached over the mill network only
    // (infra/vps/provision.sh TLS_MODE=local, docs/mill-server.md). Everything works without internet;
    // off-site backups and e-mail go out when the internet is back.
    'mill_server' => (bool) env('RICEMILL_MILL_SERVER', false),

    // The action chatbot (Assistant). Without an API key it works offline with short commands;
    // with ANTHROPIC_API_KEY it understands normal sentences (the question, earlier turns and tool
    // results are sent to Anthropic).
    'assistant' => [
        'enabled' => (bool) env('RICEMILL_ASSISTANT', true),
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('RICEMILL_ASSISTANT_MODEL', 'claude-sonnet-5-5'),
        'api_url' => env('RICEMILL_ASSISTANT_API_URL', 'https://api.anthropic.com/v1/messages'),
    ],

    // Bank notification e-mails (Accounts → Bank e-mail). The OAuth apps are the product owner's: their
    // ids and secrets live only here (.env), never in the database or on a screen. Gmail's read-only
    // scope needs Google's app verification before customers outside a test list can connect; IMAP with
    // an app password works without it.
    'bank_email' => [
        'google' => [
            'client_id' => env('BANK_EMAIL_GOOGLE_CLIENT_ID', ''),
            'client_secret' => env('BANK_EMAIL_GOOGLE_CLIENT_SECRET', ''),
        ],
        'microsoft' => [
            'client_id' => env('BANK_EMAIL_MICROSOFT_CLIENT_ID', ''),
            'client_secret' => env('BANK_EMAIL_MICROSOFT_CLIENT_SECRET', ''),
            'tenant' => env('BANK_EMAIL_MICROSOFT_TENANT', 'common'),
        ],
        'imap' => (bool) env('BANK_EMAIL_IMAP', true),
        // Empty: /admin/bank-email/callback on this server. Set it when the server is behind another name.
        'redirect_uri' => env('BANK_EMAIL_REDIRECT_URI', ''),
        'min_sync_minutes' => (int) env('BANK_EMAIL_MIN_SYNC_MINUTES', 15),
    ],

    // Reading scanned roznamcha pages: tesseract (on this server, the default), claude (the AI reader:
    // the page is sent to Anthropic; needs ANTHROPIC_API_KEY) or none (typed by hand).
    // A network scanner (eSCL / AirScan) read by the server. On a mill server it is always possible; elsewhere only
    // when this is on and the server can reach the scanner's network. Only private network addresses are accepted.
    'scanner' => ['enabled' => (bool) env('RICEMILL_SCANNER', false)],

    'ocr' => [
        'engine' => env('RICEMILL_OCR_ENGINE', 'tesseract'),
        'tesseract_path' => env('TESSERACT_PATH', 'tesseract'),
        'lang' => env('RICEMILL_OCR_LANG', 'eng'),
    ],

    'live_weight' => [
        'stale_after_seconds' => 5,
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
        'overtime_hours_month' => (int) env('RICEMILL_OVERTIME_ALERT_HOURS', 60),     // per employee in a finalized payroll
        'payroll_variance_bp' => (int) env('RICEMILL_PAYROLL_VARIANCE_BP', 1500),  // gross pay vs the month before
    ],

    'ops' => [
        'scheduler_max_minutes' => 5,
        'queue_max_minutes' => 20,
        'backup_max_hours' => 26,
        'min_free_disk_percent' => 10,
        // Failed logins across the system within 15 minutes that trigger an alert to Owners.
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

    'saas' => [
        // The product's own name (not a customer's brand). Customers are opened only by the platform admin.
        'product_name' => env('SAAS_PRODUCT_NAME', env('PRODUCT_NAME', 'MillNexa')),
        'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),
        // The plan a new customer's free trial starts on when none is chosen.
        'trial_plan' => env('SAAS_TRIAL_PLAN', 'standard'),
        // Shown to a customer whose trial is over or whose access is paused.
        'contact' => env('SAAS_CONTACT', ''),
        'billing' => [
            // Monthly invoices are issued on the 1st and due this many days later.
            'due_days' => (int) env('SAAS_INVOICE_DUE_DAYS', 10),
            // Days after the due date before an unpaid customer becomes read-only (it can look, not change).
            'grace_days' => (int) env('SAAS_GRACE_DAYS', 7),
            // Days after the due date before an unpaid customer is paused (0: never automatically).
            'suspend_after_days' => (int) env('SAAS_SUSPEND_AFTER_DAYS', 0),
            'number_prefix' => env('SAAS_INVOICE_PREFIX', 'SUB'),
            // On every invoice: who bills, and where to send the bank transfer.
            'seller_name' => env('SAAS_SELLER_NAME', ''),
            'seller_address' => env('SAAS_SELLER_ADDRESS', ''),
            'seller_ntn' => env('SAAS_SELLER_NTN', ''),
            'bank_title' => env('SAAS_BANK_TITLE', ''),
            'bank_name' => env('SAAS_BANK_NAME', ''),
            'bank_account' => env('SAAS_BANK_ACCOUNT', ''),
            'bank_iban' => env('SAAS_BANK_IBAN', ''),
        ],
    ],

];
