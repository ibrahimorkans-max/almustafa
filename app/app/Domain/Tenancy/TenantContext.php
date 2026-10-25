<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\DatabaseManager;

/**
 * Which entity the current request/job acts in, and whether it may bypass isolation.
 *
 * Every change is pushed to PostgreSQL as session settings consumed by the RLS
 * policies (app.entity_id, app.customer_id, app.rls_bypass).
 *
 * SaaS: a customer's Owner works in one of its mills, or across "all firms" of its own customer
 * (customer mode: no mill, the customer set, no bypass); only the system and the platform admin bypass. Queries made with no context at all
 * match nothing (fail closed) in both the Eloquent scope and the RLS policies.
 */
final class TenantContext
{
    private ?string $entityId = null;

    private bool $bypass = false;

    private ?string $customerId = null;

    public function __construct(private readonly DatabaseManager $db) {}

    public function entityId(): ?string
    {
        return $this->entityId;
    }

    public function requireEntityId(): string
    {
        return $this->entityId ?? throw new MissingTenantContext;
    }

    /** The customer whose mills an Owner works across (null: not in customer mode). */
    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function bypassesIsolation(): bool
    {
        return $this->bypass;
    }

    public function enterEntity(string $entityId): void
    {
        $this->set($entityId, false, null);
    }

    /** A customer's Owner: one of its mills, or (null) all mills of this customer — never another customer's. */
    public function enterCustomer(string $customerId, ?string $entityId): void
    {
        $this->set($entityId, false, $customerId);
    }

    /** The platform (provider): may read across every customer; $entityId is the working entity (null = all). */
    public function enterSuperAdmin(?string $entityId): void
    {
        $this->set($entityId, true, null);
    }

    public function clear(): void
    {
        $this->set(null, false, null);
    }

    /**
     * Run trusted system code (pre-auth lookups, provisioning, scheduler) without isolation.
     * $entityId optionally narrows the Eloquent scope while RLS is bypassed.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAsSystem(callable $callback, ?string $entityId = null): mixed
    {
        return $this->runWith($entityId, true, $callback, $this->customerId);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runInEntity(string $entityId, callable $callback): mixed
    {
        return $this->runWith($entityId, false, $callback, null);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function runWith(?string $entityId, bool $bypass, callable $callback, ?string $customerId): mixed
    {
        [$prevEntity, $prevBypass, $prevCustomer] = [$this->entityId, $this->bypass, $this->customerId];
        $this->set($entityId, $bypass, $customerId);

        try {
            return $callback();
        } finally {
            $this->set($prevEntity, $prevBypass, $prevCustomer);
        }
    }

    private function set(?string $entityId, bool $bypass, ?string $customerId): void
    {
        $this->entityId = $entityId;
        $this->bypass = $bypass;
        $this->customerId = $customerId;
        $this->applyToDatabase();
    }

    /** Re-send the current context to the database connection (e.g. after a reconnect). */
    public function applyToDatabase(): void
    {
        $connection = $this->db->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select(
            "select set_config('app.entity_id', ?, false), set_config('app.customer_id', ?, false), set_config('app.rls_bypass', ?, false)",
            [$this->entityId ?? '', $this->customerId ?? '', $this->bypass ? 'on' : 'off'],
        );
    }
}
