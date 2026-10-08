<?php

declare(strict_types=1);

namespace App\Models\Claims;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $claim_id
 * @property string $event
 * @property array<string, mixed>|null $data
 * @property string|null $actor_login_account_id
 * @property string|null $actor_person_id
 * @property Carbon $created_at
 * @property-read LoginAccount|null $actor
 */
class ClaimEvent extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['entity_id', 'claim_id', 'event', 'data', 'actor_login_account_id', 'actor_person_id', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'actor_login_account_id');
    }
}
