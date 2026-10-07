<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Domain\Tenancy\TenantContext;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Per-entity role (or the global Super Admin role when entity_id is NULL).
 *
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string $name
 * @property bool $is_system
 * @property bool $is_super_admin
 * @property bool $mfa_required
 * @property int $session_max_minutes
 */
class Role extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected static bool $allowsGlobalRows = true;

    protected $fillable = ['entity_id', 'code', 'name', 'is_system', 'is_super_admin', 'mfa_required', 'session_max_minutes'];

    /** @var list<string>|null */
    private ?array $permissionCodeCache = null;

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_super_admin' => 'boolean',
            'mfa_required' => 'boolean',
            'session_max_minutes' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_code', 'id', 'code');
    }

    /** @return HasMany<LoginAccount, $this> */
    public function loginAccounts(): HasMany
    {
        return $this->hasMany(LoginAccount::class);
    }

    /**
     * Grants of this (already loaded, so already authorised) role. Read by role id in system
     * mode so a permission check never depends on whichever working entity is active.
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return $this->permissionCodeCache ??= app(TenantContext::class)->runAsSystem(fn () => DB::table('role_permissions')
            ->where('role_id', $this->getKey())
            ->orderBy('permission_code')
            ->pluck('permission_code')
            ->all());
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCodeCache = null;
    }
}
