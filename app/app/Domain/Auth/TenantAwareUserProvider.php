<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Loads the session user before any tenant context exists (system lookup),
 * then establishes the tenant + actor context for the rest of the request.
 */
final class TenantAwareUserProvider extends EloquentUserProvider
{
    public function __construct(
        Hasher $hasher,
        string $model,
        private readonly TenantContext $tenant,
        private readonly AuthContextBinder $binder,
    ) {
        parent::__construct($hasher, $model);
        $this->withQuery(fn ($query) => $query->with(['role', 'person', 'entity']));
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        /** @var LoginAccount|null $account */
        $account = $this->tenant->runAsSystem(fn () => parent::retrieveById($identifier));
        if ($account === null || ! $account->isActive()) {
            return null;
        }

        $this->binder->bind($account);

        return $account;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null; // "remember me" is disabled
    }

    /** @param  array<string, mixed>  $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return $this->tenant->runAsSystem(fn () => parent::retrieveByCredentials($credentials));
    }
}
