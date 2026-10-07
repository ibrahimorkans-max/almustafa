<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;

    protected static bool $allowsGlobalRows = true;

    protected $fillable = ['entity_id', 'key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json', 'version' => 'integer'];
    }
}
