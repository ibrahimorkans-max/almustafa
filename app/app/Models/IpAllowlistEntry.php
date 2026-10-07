<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $login_account_id
 * @property string $cidr
 * @property string|null $note
 */
class IpAllowlistEntry extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $table = 'ip_allowlist';

    protected $fillable = ['entity_id', 'login_account_id', 'cidr', 'note'];

    /** @return BelongsTo<LoginAccount, $this> */
    public function loginAccount(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class);
    }
}
