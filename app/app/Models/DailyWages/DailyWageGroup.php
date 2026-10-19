<?php

declare(strict_types=1);

namespace App\Models\DailyWages;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A labour group that works under one person (the mukadam / jamadar): he brings the workers and takes
 * the group's pay. The workers themselves are not registered.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $name
 * @property string $leader_name
 * @property string|null $leader_phone
 * @property string|null $leader_cnic
 * @property string|null $default_category_id
 * @property int|null $usual_size
 * @property string|null $notes
 * @property string $status
 * @property-read DailyWageCategory|null $defaultCategory
 */
class DailyWageGroup extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['name', 'leader_name', 'leader_phone', 'leader_cnic', 'default_category_id', 'usual_size', 'notes', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['usual_size' => 'integer'];
    }

    /** @return BelongsTo<DailyWageCategory, $this> */
    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(DailyWageCategory::class, 'default_category_id');
    }

    public function label(): string
    {
        return $this->name.' ('.$this->leader_name.')';
    }
}
