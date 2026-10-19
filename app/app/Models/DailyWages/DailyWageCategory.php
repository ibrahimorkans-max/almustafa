<?php

declare(strict_types=1);

namespace App\Models\DailyWages;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of casual work and its rate: per person per day (or hour), or per unit of work (bag, maund,
 * ton, trip, piece). The rate is the default for new wage sheets; a sheet may use another.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $unit
 * @property int $rate_paise
 * @property string $status
 */
class DailyWageCategory extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    /** unit => [label for the quantity, label for the rate, paid per person?] */
    public const UNITS = [
        'day' => ['Days each', 'per person per day', true],
        'hour' => ['Hours each', 'per person per hour', true],
        'bag' => ['Bags', 'per bag', false],
        'maund' => ['Maund', 'per maund', false],
        'ton' => ['Tons', 'per ton', false],
        'trip' => ['Trips', 'per trip', false],
        'piece' => ['Pieces', 'per piece', false],
    ];

    protected $fillable = ['code', 'name', 'unit', 'rate_paise', 'status'];

    protected $attributes = ['status' => 'active', 'unit' => 'day'];

    protected function casts(): array
    {
        return ['rate_paise' => 'integer'];
    }

    /** Is the rate paid to each person (day, hour) rather than for the whole group's work? */
    public function perPerson(): bool
    {
        return (bool) (self::UNITS[$this->unit][2] ?? true);
    }

    public function rateLabel(): string
    {
        return self::UNITS[$this->unit][1] ?? $this->unit;
    }
}
