<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Auth\ActorContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Global settings (entity_id NULL) with optional per-entity overrides.
 * Every change is audited through the Setting model.
 */
final class SettingsService
{
    public const LOGIN_MODE = 'auth.login_mode';

    public const LOGIN_MODE_MULTI_ACCOUNT = 'multi_account';

    public const LOGIN_MODE_SWITCHER = 'switcher';

    /** Who may sign in only from an approved device: everyone | selected accounts | off. */
    public const TRUSTED_DEVICES = 'security.trusted_devices';

    public const TRUSTED_DEVICES_MODES = ['everyone', 'selected', 'off'];

    /**
     * Screen protection (watermark, hidden when out of focus, no printing). The all-mills row (set by
     * the Owner) is on, off or enforced; a mill's own row (set by its Super Admin) is on or off and
     * counts unless the Owner enforced it.
     */
    public const SCREEN_GUARD = 'security.screen_guard';

    /** How many phones and computers one person may use (0: no limit). Gate agents are not counted. */
    public const MAX_DEVICES = 'security.max_devices';

    public const MAX_DEVICES_CHOICES = [0, 1, 2, 3, 4, 5, 10];

    /** The mill's network scanner (eSCL), read by the mill server for roznamcha pages (NetworkScanner). */
    public const SCANNER_URL = 'devices.scanner_url';

    /** 'on': entries (gate, stock, expenses, roznamcha) keep where the device was, when its browser allows it. */
    public const RECORD_LOCATION = 'privacy.record_location';

    /** 'on': nobody of this mill signs in, or stays signed in, outside its sites (HR → Sites). */
    public const SIGN_IN_AREA = 'security.sign_in_area';

    /** How many months after closing a record moves from the working screens to the Office Desk (Owner, all mills). */
    public const ARCHIVE_RETENTION = 'archive.retention_months';

    /** The choices offered; at least 3 months so records still being worked on are never moved. */
    public const ARCHIVE_RETENTION_CHOICES = [3, 6, 12, 18, 24, 36, 48, 60];

    /** Messages to parties, each kind on or off for a mill (App\Domain\Messaging\PartyMessenger). */
    public const MESSAGE_EVENTS = [
        'login' => 'Login details when a party account is made',
        'money' => 'Each money entry with the party (bill, payment, receipt, invoice, reversal)',
        'unloaded' => 'Vehicle unloaded at the mill',
        'weighed' => 'Vehicle weights final (slip issued)',
    ];

    /** sms | whatsapp: how messages to parties are sent. */
    public const MESSAGE_CHANNEL = 'messages.channel';

    /** @var array<string, array{default: mixed, allowed?: list<mixed>, scope: 'global'|'entity'}> */
    public const DEFINITIONS = [
        'messages.login' => ['default' => 'on', 'allowed' => ['on', 'off'], 'scope' => 'entity'],
        'messages.money' => ['default' => 'on', 'allowed' => ['on', 'off'], 'scope' => 'entity'],
        'messages.unloaded' => ['default' => 'on', 'allowed' => ['on', 'off'], 'scope' => 'entity'],
        'messages.weighed' => ['default' => 'on', 'allowed' => ['on', 'off'], 'scope' => 'entity'],
        self::MESSAGE_CHANNEL => ['default' => 'whatsapp', 'allowed' => ['whatsapp', 'sms'], 'scope' => 'entity'],
        // Also a notification on the phones and browsers the party turned notifications on for (Firebase).
        'messages.push' => ['default' => 'on', 'allowed' => ['on', 'off'], 'scope' => 'entity'],
        // The default comes from RICEMILL_ARCHIVE_RETENTION_MONTHS; see archiveRetentionMonths().
        // Each customer sets its own (stored on each of its mills); the all-mills row is the platform's default.
        self::ARCHIVE_RETENTION => [
            'default' => null,
            'allowed' => self::ARCHIVE_RETENTION_CHOICES,
            'scope' => 'entity',
        ],
        self::LOGIN_MODE => [
            'default' => self::LOGIN_MODE_MULTI_ACCOUNT,
            'allowed' => [self::LOGIN_MODE_MULTI_ACCOUNT, self::LOGIN_MODE_SWITCHER],
            'scope' => 'global',
        ],
        // The default comes from RICEMILL_TRUSTED_DEVICES (production: everyone); see trustedDevicesMode().
        self::TRUSTED_DEVICES => [
            'default' => null,
            'allowed' => self::TRUSTED_DEVICES_MODES,
            'scope' => 'entity',
        ],
        self::SIGN_IN_AREA => ['default' => 'off', 'allowed' => ['on', 'off'], 'scope' => 'entity'],
        self::MAX_DEVICES => ['default' => 0, 'allowed' => self::MAX_DEVICES_CHOICES, 'scope' => 'entity'],
        self::SCANNER_URL => ['default' => null, 'scope' => 'entity'],
        self::RECORD_LOCATION => ['default' => 'off', 'allowed' => ['on', 'off'], 'scope' => 'entity'],
        // Preferred paper per kind of document (PaperSize::KINDS); empty: the paper chosen on the device.
        'print.paper.token' => ['default' => null, 'scope' => 'entity'],
        'print.paper.slip' => ['default' => null, 'scope' => 'entity'],
        'print.paper.invoice' => ['default' => null, 'scope' => 'entity'],
        'print.paper.voucher' => ['default' => null, 'scope' => 'entity'],
        'print.paper.statement' => ['default' => null, 'scope' => 'entity'],
        'print.paper.report' => ['default' => null, 'scope' => 'entity'],
        'print.paper.record' => ['default' => null, 'scope' => 'entity'],
        // The custom paper, width x height in mm ("100x150").
        'print.custom_paper' => ['default' => null, 'scope' => 'entity'],
        // The default comes from RICEMILL_SCREEN_GUARD; see screenGuard().
        self::SCREEN_GUARD => [
            'default' => null,
            'allowed' => ['on', 'off', 'enforced'],
            'scope' => 'entity',
        ],
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
    ) {}

    public function get(string $key, ?string $entityId = null): mixed
    {
        $definition = $this->definition($key);

        return $this->tenant->runAsSystem(function () use ($key, $entityId, $definition): mixed {
            if ($entityId !== null) {
                $override = Setting::query()->where('entity_id', $entityId)->where('key', $key)->first();
                if ($override !== null) {
                    return $override->value;
                }
            }

            return Setting::query()->whereNull('entity_id')->where('key', $key)->first()->value ?? $definition['default'];
        });
    }

    public function put(string $key, mixed $value, ?string $entityId = null): void
    {
        $definition = $this->definition($key);
        if (isset($definition['allowed']) && ! in_array($value, $definition['allowed'], true)) {
            throw new InvalidArgumentException("Invalid value for {$key}.");
        }
        if ($definition['scope'] === 'global' && $entityId !== null) {
            throw new InvalidArgumentException("{$key} is a global setting.");
        }
        // A value for all mills applies to every customer: the platform admin's (or the system's) only.
        $actor = $this->actor->account();
        if ($entityId === null && $actor !== null && ! $actor->isPlatformAdmin()) {
            throw new InvalidArgumentException("Only the platform admin can set {$key} for every customer.");
        }

        $write = function () use ($key, $value, $entityId): void {
            DB::transaction(function () use ($key, $value, $entityId): void {
                $setting = Setting::query()
                    ->where('key', $key)
                    ->when($entityId === null, fn ($q) => $q->whereNull('entity_id'), fn ($q) => $q->where('entity_id', $entityId))
                    ->lockForUpdate()
                    ->first() ?? new Setting(['entity_id' => $entityId, 'key' => $key]);

                $setting->value = $value;
                $setting->updated_by = $this->actor->accountId();
                $setting->save();
            });
        };

        // Global rows are writable only with isolation bypass (Owner / system).
        $entityId === null ? $this->tenant->runAsSystem($write) : $write();
    }

    /** Months after closing before a record moves to the Office Desk: the Owner's choice, else RICEMILL_ARCHIVE_RETENTION_MONTHS. */
    public function archiveRetentionMonths(?string $entityId = null): int
    {
        $months = $this->get(self::ARCHIVE_RETENTION, $entityId) ?? config('ricemill.archive.retention_months');

        return max(3, (int) $months);
    }

    public function loginMode(): string
    {
        return (string) $this->get(self::LOGIN_MODE);
    }

    public function trustedDevicesMode(?string $entityId): string
    {
        $mode = $this->get(self::TRUSTED_DEVICES, $entityId) ?? config('ricemill.security.trusted_devices_default');

        return in_array($mode, self::TRUSTED_DEVICES_MODES, true) ? (string) $mode : 'off';
    }

    /**
     * Screen protection for one mill (null = "All firms" mode): the all-mills choice, the mill's own
     * choice (null = follows all mills) and the result.
     *
     * @return array{all: string, mill: string|null, enforced: bool, on: bool}
     */
    public function screenGuard(?string $entityId): array
    {
        $all = $this->tenant->runAsSystem(fn () => Setting::query()->whereNull('entity_id')->where('key', self::SCREEN_GUARD)->first()?->value)
            ?? (config('ricemill.security.screen_guard') ? 'on' : 'off');
        $mill = $entityId === null ? null
            : $this->tenant->runAsSystem(fn () => Setting::query()->where('entity_id', $entityId)->where('key', self::SCREEN_GUARD)->first()?->value);
        $enforced = $all === 'enforced';

        return ['all' => (string) $all, 'mill' => $mill === null ? null : (string) $mill, 'enforced' => $enforced,
            'on' => $enforced || ($mill ?? $all) !== 'off'];
    }

    /** Whether this kind of message to parties is on for the mill. */
    public function messageOn(string $event, ?string $entityId): bool
    {
        return isset(self::MESSAGE_EVENTS[$event]) && $this->get('messages.'.$event, $entityId) !== 'off';
    }

    public function pushOn(?string $entityId): bool
    {
        return $this->get('messages.push', $entityId) !== 'off';
    }

    public function messageChannel(?string $entityId): string
    {
        return $this->get(self::MESSAGE_CHANNEL, $entityId) === 'sms' ? 'sms' : 'whatsapp';
    }

    /** Removes a mill's own value, so the all-mills value applies again. */
    public function forget(string $key, string $entityId): void
    {
        $this->definition($key);
        Setting::query()->where('entity_id', $entityId)->where('key', $key)->delete();
    }

    /** @return array{default: mixed, allowed?: list<mixed>, scope: 'global'|'entity'} */
    private function definition(string $key): array
    {
        return self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting {$key}.");
    }
}
