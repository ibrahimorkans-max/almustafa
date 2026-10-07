<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $requirement_id
 * @property string $party_id
 * @property string $channel
 * @property Carbon $notified_at
 * @property-read Party $party
 */
class RequirementRecipient extends Model
{
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const UPDATED_AT = null;

    protected $fillable = ['requirement_id', 'party_id', 'channel', 'notified_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
