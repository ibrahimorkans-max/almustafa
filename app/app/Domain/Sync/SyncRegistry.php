<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Gate\GateService;
use App\Domain\Production\ProductionService;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\Handlers\AttendanceHandler;
use App\Domain\Sync\Handlers\GateHandlers;
use App\Domain\Sync\Handlers\MasterUpsertHandler;
use App\Domain\Sync\Handlers\ProductionHandlers;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Brand;
use App\Models\Masters\Driver;
use App\Models\Masters\Godown;
use App\Models\Masters\MasterModel;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Models\Production\ProductionBatch;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Read-only feeds: records devices pull but change only through append-only events.
     * type => [model, read permissions (any of)].
     *
     * @var array<string, array{0: class-string<Model>, 1: list<string>}>
     */
    public const FEEDS = [
        'gate_entry' => [GateEntry::class, ['gate.entry', 'weighment.capture', 'lab.test', 'lab.decide']],
        'sauda' => [Sauda::class, ['deal.view', 'gate.entry']],
        'production_batch' => [ProductionBatch::class, ['production.view', 'production.log']],
    ];

    /** @var array<string, SyncHandler> extra handlers registered by later phases / tests */
    private array $extra = [];

    /** @var array<string, SyncHandler>|null */
    private ?array $appendOnly = null;

    public function __construct(
        private readonly ConflictLog $conflicts,
        private readonly Container $container,
    ) {}

    public function register(SyncHandler $handler): void
    {
        $this->extra[$handler->type()] = $handler;
    }

    /**
     * Append-only event types (gate, weighbridge, lab), built on first use.
     *
     * @return array<string, SyncHandler>
     */
    private function appendOnlyHandlers(): array
    {
        if ($this->appendOnly === null) {
            $this->appendOnly = [];
            $handlers = [
                ...GateHandlers::all($this->container->make(GateService::class)),
                ...ProductionHandlers::all($this->container->make(ProductionService::class)),
                $this->container->make(AttendanceHandler::class),
            ];
            foreach ($handlers as $handler) {
                $this->appendOnly[$handler->type()] = $handler;
            }
        }

        return $this->appendOnly;
    }

    public function handler(string $eventType): ?SyncHandler
    {
        if (isset($this->extra[$eventType])) {
            return $this->extra[$eventType];
        }
        if (isset($this->appendOnlyHandlers()[$eventType])) {
            return $this->appendOnlyHandlers()[$eventType];
        }

        [$type, $op] = array_pad(explode('.', $eventType, 2), 2, null);
        if ($op !== 'upsert' || ! isset(self::MASTERS[$type])) {
            return null;
        }
        [$model, , $write] = self::MASTERS[$type];

        return new MasterUpsertHandler($model, $write, $this->conflicts);
    }

    /** @return class-string<Model>|null */
    public function modelFor(string $type): ?string
    {
        return self::MASTERS[$type][0] ?? self::FEEDS[$type][0] ?? null;
    }

    /** @return class-string<MasterModel>|null */
    public function masterModelFor(string $type): ?string
    {
        return self::MASTERS[$type][0] ?? null;
    }

    public function canRead(LoginAccount $account, string $type): bool
    {
        foreach (self::MASTERS[$type][1] ?? self::FEEDS[$type][1] ?? [] as $permission) {
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
        foreach (array_keys(self::FEEDS) as $type) {
            $out[] = ['type' => $type, 'read' => $this->canRead($account, $type), 'write' => false];
        }

        return $out;
    }

    /** @return list<array{type: string, allowed: bool}> append-only event types the account may send */
    public function eventCapabilities(LoginAccount $account): array
    {
        return array_values(array_map(
            fn (SyncHandler $h) => ['type' => $h->type(), 'allowed' => $h->canWrite($account)],
            $this->appendOnlyHandlers(),
        ));
    }
}
