<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A supervisor's decision on a flagged punch. Append-only: a second thought is a new review; the
 * latest one counts, and all stay visible.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $attendance_log_id
 * @property string $decision accept|reject
 * @property string $reason
 * @property string $reviewed_by
 * @property Carbon $reviewed_at
 * @property-read LoginAccount $reviewer
 */
class AttendanceReview extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public const ACCEPT = 'accept';

    public const REJECT = 'reject';

    public $timestamps = false;

    protected $fillable = ['attendance_log_id', 'decision', 'reason', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'reviewed_by');
    }
}
