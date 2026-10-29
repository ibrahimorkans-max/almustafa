<?php

declare(strict_types=1);

namespace App\Models\Banking;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reading of a mailbox: how many e-mails were seen, imported, duplicates and failures.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $email_connection_id
 * @property string $trigger schedule | manual
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int $fetched
 * @property int $imported
 * @property int $duplicates
 * @property int $failed
 * @property string|null $error
 */
class EmailSyncRun extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['email_connection_id', 'trigger', 'started_at', 'finished_at', 'fetched', 'imported', 'duplicates', 'failed', 'error'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'fetched' => 'integer', 'imported' => 'integer', 'duplicates' => 'integer', 'failed' => 'integer'];
    }

    /** @return BelongsTo<EmailConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(EmailConnection::class, 'email_connection_id');
    }
}
