<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;

/**
 * The SaaS product's own company and its office (made by `saas:create-platform-owner` to hold the product owner's
 * login). It is not a customer and its office is not a mill: it is left out of every list of customers and mills
 * (customers, weighing modes, sender identities, usage, statements, users), and the product owner gets no mill screens.
 */
final class PlatformCompany
{
    /** @return list<string> the customer rows that hold a platform admin's login (read each time: one small query) */
    public static function customerIds(): array
    {
        return app(TenantContext::class)->runAsSystem(fn () => Entity::query()
            ->whereIn('id', LoginAccount::query()->where('is_platform_admin', true)->select('entity_id'))
            ->whereNotNull('customer_id')->distinct()->pluck('customer_id')->all());
    }

    /** @return list<string> the office "mills" of the product's own company */
    public static function entityIds(): array
    {
        return app(TenantContext::class)->runAsSystem(fn () => Entity::query()->whereIn('customer_id', self::customerIds())->pluck('id')->all());
    }

    public static function is(?string $customerId): bool
    {
        return $customerId !== null && in_array($customerId, self::customerIds(), true);
    }
}
