<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A supporting document attached to a record (App\Domain\Files\Attachments). Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $attachable_type gate_entry | purchase_bill | payment | approval_request
 * @property string $attachable_id
 * @property string $stored_file_id
 * @property string $kind
 * @property string|null $note
 * @property string|null $uploaded_by
 * @property Carbon $created_at
 * @property-read StoredFile $file
 * @property-read LoginAccount|null $uploader
 */
class Attachment extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['attachable_type', 'attachable_id', 'stored_file_id', 'kind', 'note', 'uploaded_by'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'uploaded_by');
    }
}
