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

    /** @var array<string, array{default: mixed, allowed?: list<mixed>, scope: 'global'|'entity'}> */
    public const DEFINITIONS = [
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
