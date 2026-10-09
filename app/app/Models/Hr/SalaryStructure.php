<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One effective-dated version of an employee's pay. Append-only: a raise or a correction is a new
 * version (the previous one stays, linked through supersedes_id when it replaces the same date).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property Carbon $effective_from
 * @property string $pay_basis monthly|daily|piece_rate
 * @property string $reason
 * @property string|null $supersedes_id
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Employee $employee
 * @property-read Collection<int, SalaryStructureLine> $lines
 */
class SalaryStructure extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const BASES = ['monthly' => 'Monthly salary', 'daily' => 'Daily wage', 'piece_rate' => 'Piece rate'];

    protected $fillable = ['employee_id', 'effective_from', 'pay_basis', 'reason', 'supersedes_id', 'created_at'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<SalaryStructureLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalaryStructureLine::class);
    }

    public function totalPaise(string $kind): int
    {
        return (int) $this->lines->filter(fn (SalaryStructureLine $l) => $l->component->kind === $kind)->sum('amount_paise');
    }
}
