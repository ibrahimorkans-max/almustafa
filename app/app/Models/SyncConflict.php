<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $sync_event_id
 * @property string $type
 * @property string|null $record_id
 * @property string $resolution
 * @property string $reason
 * @property array<string, mixed> $client_payload
 * @property array<string, mixed>|null $server_snapshot
 * @property string|null $review_status
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property int $version
 * @property Carbon $created_at
 */
class SyncConflict extends Model
{
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;

    public const REVIEW_OPEN = 'open';

    public const REVIEW_APPLIED = 'applied';

    public const REVIEW_DISMISSED = 'dismissed';

    protected $fillable = [
        'entity_id', 'sync_event_id', 'type', 'record_id', 'resolution', 'reason', 'client_payload', 'server_snapshot', 'review_status',
    ];

    protected function casts(): array
    {
        return [
            'client_payload' => 'array',
            'server_snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->review_status === self::REVIEW_OPEN;
    }
}
