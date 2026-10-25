<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Application-level isolation (works on every database driver):
 *  - entity selected            → rows of that entity (+ global rows for models that allow them)
 *  - customer mode              → rows of that customer's mills (an Owner across "all firms"; SaaS)
 *  - no entity, bypass enabled  → everything (system / platform)
 *  - no context at all          → nothing (fail closed)
 */
final class EntityScope implements Scope
{
    public function __construct(
        private readonly string $column = 'entity_id',
        private readonly bool $includeGlobalRows = false,
    ) {}

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $column = $model->qualifyColumn($this->column);

        if ($context->entityId() !== null) {
            if ($this->includeGlobalRows) {
                $builder->where(fn (Builder $q) => $q->where($column, $context->entityId())->orWhereNull($column));
            } else {
                $builder->where($column, $context->entityId());
            }

            return;
        }

        if ($context->customerId() !== null && ! $context->bypassesIsolation()) {
            $mills = fn ($q) => $q->select('id')->from('entities')->where('customer_id', $context->customerId());
            $this->includeGlobalRows
                ? $builder->where(fn (Builder $q) => $q->whereIn($column, $mills)->orWhereNull($column))
                : $builder->whereIn($column, $mills);

            return;
        }

        if (! $context->bypassesIsolation()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
