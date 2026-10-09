<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Validation\Rules\Unique;

/** Shared bits for the HR screens. */
final class HrScope
{
    public const GROUP = 'HR';

    /** Uniqueness within the working entity only (the same code may exist in another firm). */
    public static function inEntity(Unique $rule): Unique
    {
        return $rule->where('entity_id', app(TenantContext::class)->entityId());
    }
}
