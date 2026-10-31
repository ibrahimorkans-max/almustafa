<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A gate pass cancelled, with the reason. Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $gate_pass_id
 * @property string $reason
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class GatePassCancellation extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['gate_pass_id', 'reason', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
