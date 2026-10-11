<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Units of work done by one employee on one day (bags loaded, maund handled …), the basis of
 * piece-rate wages. Append-only; a wrong entry is offset by a correction with a negative quantity.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property Carbon $work_date
 * @property string $unit
 * @property string $quantity
 * @property string $source_type manual|gate_entry|production_batch|correction
 * @property string|null $source_id
 * @property string|null $source_key
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Employee $employee
 */
class PieceWorkEntry extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const UNITS = ['bag' => 'Bag', 'maund' => 'Maund (40 kg)', 'ton' => 'Ton', 'piece' => 'Piece', 'trip' => 'Trip'];

    protected $fillable = ['employee_id', 'work_date', 'unit', 'quantity', 'source_type', 'source_id', 'source_key', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'quantity' => 'decimal:3', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
