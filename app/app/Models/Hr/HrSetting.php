<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One effective-dated value of a payroll setting (rate, slab table, threshold). Append-only:
 * a change is a new row from a new date; earlier months keep the value that applied to them.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $key
 * @property mixed $value
 * @property Carbon $effective_from
 * @property string|null $note
 * @property Carbon $created_at
 */
class HrSetting extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['key', 'value', 'effective_from', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['value' => 'array', 'effective_from' => 'date', 'created_at' => 'datetime'];
    }
}
