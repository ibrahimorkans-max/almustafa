<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Maker-checker request. Only App\Domain\Approvals\ApprovalService changes its status.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $action
 * @property array<string, mixed> $payload
 * @property string $summary
 * @property string|null $reason
 * @property string $status
 * @property string $maker_login_account_id
 * @property string $maker_person_id
 * @property string|null $checker_login_account_id
 * @property string|null $checker_person_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $executed_at
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 */
class ApprovalRequest extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'entity_id', 'action', 'payload', 'summary', 'reason', 'maker_login_account_id', 'maker_person_id', 'expires_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_PENDING];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
            'expires_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function maker(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'maker_login_account_id');
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'checker_login_account_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
