<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\TenantContext;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real human. May own login accounts in several entities.
 * Visible in an entity if it was registered there or has a login account there.
 *
 * @property string $id
 * @property string $full_name
 * @property string|null $phone
 * @property string|null $email
 * @property string $created_in_entity_id
 */
class Person extends Model
{
    use Auditable;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $table = 'persons';

    protected $fillable = ['full_name', 'phone', 'email'];

    protected static function booted(): void
    {
        static::addGlobalScope('entity', function (Builder $builder): void {
            $context = app(TenantContext::class);
            $entityId = $context->entityId();

            if ($entityId === null) {
                if (! $context->bypassesIsolation()) {
                    $builder->whereRaw('1 = 0');
                }

                return;
            }

            $builder->where(function (Builder $q) use ($entityId): void {
                $q->where('persons.created_in_entity_id', $entityId)
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('login_accounts')
                        ->whereColumn('login_accounts.person_id', 'persons.id')
                        ->where('login_accounts.entity_id', $entityId));
            });
        });

        static::creating(function (Person $person): void {
            $person->created_in_entity_id ??= app(TenantContext::class)->requireEntityId();
        });
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return HasMany<LoginAccount, $this> */
    public function loginAccounts(): HasMany
    {
        return $this->hasMany(LoginAccount::class);
    }
}
