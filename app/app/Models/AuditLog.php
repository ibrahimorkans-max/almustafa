<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\EntityScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Read-only view of audit_log. Writes go exclusively through AuditLogger.
 *
 * @property string $id
 * @property string $chain
 * @property int $seq
 * @property string|null $entity_id
 * @property Carbon $occurred_at
 * @property string|null $actor_login_account_id
 * @property string|null $actor_person_id
 * @property bool $acting_as_super_admin
 * @property string|null $device_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $meta
 * @property string $prev_hash
 * @property string $hash
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new EntityScope);

        static::saving(fn () => throw new LogicException('audit_log is append-only; use AuditLogger.'));
        static::deleting(fn () => throw new LogicException('audit_log is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'occurred_at' => 'datetime',
            'acting_as_super_admin' => 'boolean',
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'actor_login_account_id');
    }
}
