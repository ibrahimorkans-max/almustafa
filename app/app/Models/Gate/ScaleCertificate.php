<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $scale_id
 * @property string $type
 * @property string $certificate_no
 * @property string|null $issued_by
 * @property Carbon $issued_on
 * @property Carbon $valid_until
 * @property string|null $file_sha256
 * @property-read Scale $scale
 */
class ScaleCertificate extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const TYPE_STAMPING = 'stamping';

    public const TYPE_CALIBRATION = 'calibration';

    protected $fillable = ['scale_id', 'type', 'certificate_no', 'issued_by', 'issued_on', 'valid_until', 'file_sha256'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'valid_until' => 'date', 'version' => 'integer'];
    }

    /** @return BelongsTo<Scale, $this> */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }

    public function daysLeft(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->valid_until->startOfDay(), false);
    }
}
