<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\CrossEntityWrite;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every model that has an `entity_id`.
 *
 * Models whose NULL entity_id means "global row" (roles, settings) set
 * `protected static bool $allowsGlobalRows = true;`.
 *
 * @mixin Model
 */
trait BelongsToEntity
{
    public static function bootBelongsToEntity(): void
    {
        static::addGlobalScope(new EntityScope('entity_id', static::allowsGlobalRows()));

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $attributes = $model->getAttributes();

            if (! array_key_exists('entity_id', $attributes) || ($attributes['entity_id'] === null && ! static::allowsGlobalRows())) {
                $model->setAttribute('entity_id', $context->requireEntityId());
            }

            static::guardEntity($model);
        });

        static::updating(fn (Model $model) => static::guardEntity($model));
        static::deleting(fn (Model $model) => static::guardEntity($model));
    }

    public static function allowsGlobalRows(): bool
    {
        return property_exists(static::class, 'allowsGlobalRows') && static::$allowsGlobalRows;
    }

    /**
     * Outside bypass mode a model may only be written inside the current entity — or, for a customer's
     * Owner (in one of its mills or across "all firms"), inside one of that customer's mills: its own sign-in
     * session and devices belong to its home mill while it works in another. Never another customer's.
     */
    protected static function guardEntity(Model $model): void
    {
        $context = app(TenantContext::class);
        if ($context->bypassesIsolation()) {
            return;
        }

        $entityId = $model->getAttribute('entity_id');
        $original = $model->getOriginal('entity_id');
        if ($entityId === null || ($model->exists && $original !== $entityId)) {
            throw new CrossEntityWrite($model::class);
        }
        if ($entityId === $context->entityId()) {
            return;
        }
        if ($context->customerId() !== null
            && $context->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('customer_id')) === $context->customerId()) {
            return;
        }

        throw new CrossEntityWrite($model::class);
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
