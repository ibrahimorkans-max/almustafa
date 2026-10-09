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
 * A firm / mill with its own books, stock, parties and settings.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $ntn
 * @property string|null $strn
 * @property string|null $province
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postal_code
 * @property string $status
 * @property int $version
 */
class Entity extends Model
{
    use Auditable;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['code', 'name', 'legal_name', 'ntn', 'strn', 'province', 'phone', 'address', 'city', 'postal_code', 'status'];

    /** @var list<string> */
    protected array $auditMasked = ['ntn', 'strn'];

    protected static function booted(): void
    {
        // An entity row is visible to its own members; Super Admin / system see all entities
        // regardless of the working entity (so they can pick one).
        static::addGlobalScope('entity', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if ($context->bypassesIsolation()) {
                return;
            }
            $context->entityId() === null
                ? $builder->whereRaw('1 = 0')
                : $builder->whereKey($context->entityId());
        });
    }

    protected function casts(): array
    {
        return [
            'ntn' => 'encrypted',
            'strn' => 'encrypted',
            'version' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasMany<LoginAccount, $this> */
    public function loginAccounts(): HasMany
    {
        return $this->hasMany(LoginAccount::class);
    }
}
