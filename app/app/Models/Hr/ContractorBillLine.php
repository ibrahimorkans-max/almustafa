<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a contractor bill: a worker's man-days, or a unit of piece work, × rate. Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $contractor_bill_id
 * @property string|null $employee_id
 * @property string $description
 * @property string $quantity
 * @property string $unit
 * @property int $rate_paise
 * @property int $amount_paise
 * @property array<string, mixed>|null $source
 * @property Carbon $created_at
 * @property-read Employee|null $employee
 */
class ContractorBillLine extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['contractor_bill_id', 'employee_id', 'description', 'quantity', 'unit', 'rate_paise', 'amount_paise', 'source', 'created_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'rate_paise' => 'integer', 'amount_paise' => 'integer', 'source' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
