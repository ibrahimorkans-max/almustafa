<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A month closed for posting. Locking is direct (`period.lock`); unlocking goes through maker-checker.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $period
 * @property bool $locked
 * @property string|null $locked_by
 * @property Carbon|null $locked_at
 * @property int $version
 */
class PeriodLock extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;

    protected $fillable = ['period', 'locked', 'locked_by', 'locked_at'];

    protected function casts(): array
    {
        return ['locked' => 'boolean', 'locked_at' => 'datetime', 'version' => 'integer'];
    }
}
