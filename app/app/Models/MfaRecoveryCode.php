<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $login_account_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
class MfaRecoveryCode extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['entity_id', 'login_account_id', 'code_hash', 'used_at'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
