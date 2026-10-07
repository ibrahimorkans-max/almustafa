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

    /** @var array<string, array{default: mixed, allowed?: list<mixed>, scope: 'global'|'entity'}> */
    public const DEFINITIONS = [
        self::LOGIN_MODE => [
            'default' => self::LOGIN_MODE_MULTI_ACCOUNT,
            'allowed' => [self::LOGIN_MODE_MULTI_ACCOUNT, self::LOGIN_MODE_SWITCHER],
            'scope' => 'global',
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

        // Global rows are writable only with isolation bypass (Super Admin / system).
        $entityId === null ? $this->tenant->runAsSystem($write) : $write();
    }

    public function loginMode(): string
    {
        return (string) $this->get(self::LOGIN_MODE);
    }

    /** @return array{default: mixed, allowed?: list<mixed>, scope: 'global'|'entity'} */
    private function definition(string $key): array
    {
        return self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting {$key}.");
    }
}
