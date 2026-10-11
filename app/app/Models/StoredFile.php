<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Content-addressed private file (weighbridge photo, certificate scan). Same content = same row.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $sha256
 * @property string $mime
 * @property int $size_bytes
 * @property string $path
 * @property string|null $device_id
 */
class StoredFile extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    // CSV / text: biometric attendance files kept as evidence of an import.
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'application/pdf', 'text/csv', 'text/plain'];

    public const MAX_BYTES = 5 * 1024 * 1024;

    protected $fillable = ['sha256', 'mime', 'size_bytes', 'path', 'device_id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
