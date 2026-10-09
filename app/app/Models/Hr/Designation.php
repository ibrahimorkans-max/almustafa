<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $name
 * @property string $status
 * @property int $version
 */
class Designation extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['name', 'status'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
