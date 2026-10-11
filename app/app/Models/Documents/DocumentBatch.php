<?php

declare(strict_types=1);

namespace App\Models\Documents;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A bulk generation on the queue: progress, failures with reasons, and the combined PDF and ZIP.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $doc_type
 * @property string $label
 * @property list<string> $source_ids
 * @property array<string, mixed> $options
 * @property string $status queued|running|done|failed
 * @property int $total
 * @property int $done_count
 * @property list<string>|null $document_ids
 * @property array<string, string>|null $failed
 * @property string|null $pdf_path
 * @property string|null $zip_path
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class DocumentBatch extends Model
{
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = ['doc_type', 'label', 'source_ids', 'options', 'total'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::QUEUED];

    protected function casts(): array
    {
        return [
            'source_ids' => 'array', 'options' => 'array', 'total' => 'integer', 'done_count' => 'integer', 'document_ids' => 'array',
            'failed' => 'array', 'attempts' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function percent(): int
    {
        return $this->total === 0 ? 100 : (int) floor($this->done_count * 100 / $this->total);
    }
}
