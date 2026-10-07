<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A weighbridge. Weighing is only trusted while it holds a valid stamping certificate.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $capacity_kg
 * @property string $resolution_kg
 * @property string|null $location
 * @property string $status
 * @property int $version
 */
class Scale extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['code', 'name', 'capacity_kg', 'resolution_kg', 'location', 'status'];

    protected function casts(): array
    {
        return ['capacity_kg' => 'decimal:3', 'resolution_kg' => 'decimal:3', 'version' => 'integer'];
    }

    /** @return HasMany<ScaleCertificate, $this> */
    public function certificates(): HasMany
    {
        return $this->hasMany(ScaleCertificate::class)->orderByDesc('valid_until');
    }

    /** A stamping (Legal Metrology) certificate valid on the given day. */
    public function hasValidStampingOn(CarbonInterface $day): bool
    {
        return $this->certificates()->where('type', ScaleCertificate::TYPE_STAMPING)
            ->whereDate('issued_on', '<=', $day)->whereDate('valid_until', '>=', $day)->exists();
    }
}
