<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $salary_structure_id
 * @property string $salary_component_id
 * @property int $amount_paise
 * @property string|null $unit
 * @property-read SalaryComponent $component
 */
class SalaryStructureLine extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['salary_structure_id', 'salary_component_id', 'amount_paise', 'unit', 'created_at'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<SalaryComponent, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
