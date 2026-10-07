<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something a person should look at (outturn drift, unaccounted loss, shrinkage, …).
 * Alerts are acknowledged with a note, never deleted.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string|null $body
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $data
 * @property string $status
 * @property string|null $acknowledged_by
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledgement_note
 * @property Carbon $created_at
 */
class Alert extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;

    protected $fillable = ['type', 'severity', 'title', 'body', 'subject_type', 'subject_id', 'data'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'severity' => 'warning'];

    protected function casts(): array
    {
        return ['data' => 'array', 'acknowledged_at' => 'datetime', 'version' => 'integer'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
