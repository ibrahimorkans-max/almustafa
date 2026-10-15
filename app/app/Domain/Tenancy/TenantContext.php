<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\DatabaseManager;

/**
 * Which entity the current request/job acts in, and whether it may bypass isolation.
 *
 * Every change is pushed to PostgreSQL as session settings consumed by the RLS
 * policies (app.entity_id, app.rls_bypass). Queries made with no context at all
 * match nothing (fail closed) in both the Eloquent scope and the RLS policies.
 */
final class TenantContext
{
    private ?string $entityId = null;

    private bool $bypass = false;

    public function __construct(private readonly DatabaseManager $db) {}

    public function entityId(): ?string
    {
        return $this->entityId;
    }

    public function requireEntityId(): string
    {
        return $this->entityId ?? throw new MissingTenantContext;
    }

    public function bypassesIsolation(): bool
    {
        return $this->bypass;
    }

    public function enterEntity(string $entityId): void
    {
        $this->set($entityId, false);
    }

    /** Owner: may read across entities; $entityId is the working entity (null = all). */
    public function enterSuperAdmin(?string $entityId): void
    {
        $this->set($entityId, true);
    }

    public function clear(): void
    {
        $this->set(null, false);
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
        return $this->runWith($entityId, true, $callback);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runInEntity(string $entityId, callable $callback): mixed
    {
        return $this->runWith($entityId, false, $callback);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function runWith(?string $entityId, bool $bypass, callable $callback): mixed
    {
        [$prevEntity, $prevBypass] = [$this->entityId, $this->bypass];
        $this->set($entityId, $bypass);

        try {
            return $callback();
        } finally {
            $this->set($prevEntity, $prevBypass);
        }
    }

    private function set(?string $entityId, bool $bypass): void
    {
        $this->entityId = $entityId;
        $this->bypass = $bypass;
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
            "select set_config('app.entity_id', ?, false), set_config('app.rls_bypass', ?, false)",
            [$this->entityId ?? '', $this->bypass ? 'on' : 'off'],
        );
    }
}
