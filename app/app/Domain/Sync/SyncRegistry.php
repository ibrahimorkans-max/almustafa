<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\Handlers\MasterUpsertHandler;
use App\Models\LoginAccount;
use App\Models\Masters\Brand;
use App\Models\Masters\Driver;
use App\Models\Masters\Godown;
use App\Models\Masters\MasterModel;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Vehicle;

/**
 * What devices may push and pull. Later phases register their handlers/types here.
 */
final class SyncRegistry
{
    /**
     * type => [model, read permissions (any of), write permissions (any of)].
     * Gate operators may register vehicles and drivers at the gate, also offline.
     *
     * @var array<string, array{0: class-string<MasterModel>, 1: list<string>, 2: list<string>}>
     */
    public const MASTERS = [
        'party' => [Party::class, ['master.view'], ['master.manage']],
        'vehicle' => [Vehicle::class, ['master.view', 'gate.entry'], ['master.manage', 'gate.entry']],
        'driver' => [Driver::class, ['master.view', 'gate.entry'], ['master.manage', 'gate.entry']],
        'product' => [Product::class, ['master.view'], ['master.manage']],
        'brand' => [Brand::class, ['master.view'], ['master.manage']],
        'godown' => [Godown::class, ['master.view'], ['master.manage']],
        'tax_rate' => [TaxRate::class, ['master.view'], ['master.manage']],
    ];

    /** @var array<string, SyncHandler> extra handlers registered by later phases / tests */
    private array $extra = [];

    public function __construct(private readonly ConflictLog $conflicts) {}

    public function register(SyncHandler $handler): void
    {
        $this->extra[$handler->type()] = $handler;
    }

    public function handler(string $eventType): ?SyncHandler
    {
        if (isset($this->extra[$eventType])) {
            return $this->extra[$eventType];
        }

        [$type, $op] = array_pad(explode('.', $eventType, 2), 2, null);
        if ($op !== 'upsert' || ! isset(self::MASTERS[$type])) {
            return null;
        }
        [$model, , $write] = self::MASTERS[$type];

        return new MasterUpsertHandler($model, $write, $this->conflicts);
    }

    /** @return class-string<MasterModel>|null */
    public function modelFor(string $type): ?string
    {
        return self::MASTERS[$type][0] ?? null;
    }

    public function canRead(LoginAccount $account, string $type): bool
    {
        foreach (self::MASTERS[$type][1] ?? [] as $permission) {
            if ($account->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{type: string, read: bool, write: bool}> */
    public function capabilities(LoginAccount $account): array
    {
        $out = [];
        foreach (array_keys(self::MASTERS) as $type) {
            $out[] = [
                'type' => $type,
                'read' => $this->canRead($account, $type),
                'write' => (bool) $this->handler($type.'.upsert')?->canWrite($account),
            ];
        }

        return $out;
    }
}
