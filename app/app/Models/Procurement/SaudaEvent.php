<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lifecycle timestamp of a sauda (append-only): who did what, when.
 *
 * @property string $id
 * @property string $sauda_id
 * @property string $event
 * @property array<string, mixed>|null $data
 * @property string|null $actor_login_account_id
 * @property Carbon $occurred_at
 * @property-read LoginAccount|null $actor
 */
class SaudaEvent extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['entity_id', 'sauda_id', 'event', 'data', 'actor_login_account_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'actor_login_account_id');
    }
}
