<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Super Admin's (or the Owner's) check of a recorded weight. Append-only: a second look is a new
 * check; the latest one counts, and all stay visible.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $weighment_id
 * @property string $decision ok|query
 * @property string|null $note
 * @property string $checked_by
 * @property Carbon $checked_at
 * @property-read LoginAccount $checker
 * @property-read Weighment $weighment
 */
class WeighmentCheck extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public const OK = 'ok';

    public const QUERY = 'query';

    public $timestamps = false;

    protected $fillable = ['weighment_id', 'decision', 'note', 'checked_by', 'checked_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'checked_by');
    }

    /** @return BelongsTo<Weighment, $this> */
    public function weighment(): BelongsTo
    {
        return $this->belongsTo(Weighment::class);
    }
}
