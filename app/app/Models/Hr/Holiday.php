<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entity_id
 * @property Carbon $date
 * @property string $name
 * @property string $kind national|festival|local
 */
class Holiday extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const KINDS = ['national' => 'National', 'festival' => 'Festival', 'local' => 'Local'];

    protected $fillable = ['date', 'name', 'kind'];

    protected function casts(): array
    {
        return ['date' => 'date', 'version' => 'integer'];
    }
}
