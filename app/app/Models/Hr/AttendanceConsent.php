<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The employee accepted the attendance notice (location and selfie at punch time only). Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string $login_account_id
 * @property string|null $device_id
 * @property string $notice_version
 * @property Carbon $accepted_at
 * @property string|null $ip
 */
class AttendanceConsent extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['employee_id', 'login_account_id', 'device_id', 'notice_version', 'accepted_at', 'ip'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}
