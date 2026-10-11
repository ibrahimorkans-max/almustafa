<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property bool $paid
 * @property string $annual_days
 * @property bool $carry_forward
 * @property string $max_carry_days
 * @property bool $half_day_allowed
 * @property string $status
 */
class LeaveType extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    /**
     * Example defaults — the owner or accountant must check them against the state's Shops &
     * Establishments / Factories Act rules: [code, name, paid, annual days, carry forward, max carry].
     */
    public const DEFAULTS = [
        // Factories Act 1934 / Punjab Shops and Establishments Ordinance 1969 (examples; the owner confirms them).
        ['CL', 'Casual leave', true, '10.0', false, '0.0'],
        ['SL', 'Sick leave', true, '16.0', false, '0.0'],
        ['EL', 'Annual (earned) leave', true, '14.0', true, '28.0'],
        ['LWP', 'Leave without pay', false, '0.0', false, '0.0'],
    ];

    protected $fillable = ['code', 'name', 'paid', 'annual_days', 'carry_forward', 'max_carry_days', 'half_day_allowed', 'status'];

    protected function casts(): array
    {
        return [
            'paid' => 'boolean', 'annual_days' => 'decimal:1', 'carry_forward' => 'boolean', 'max_carry_days' => 'decimal:1',
            'half_day_allowed' => 'boolean', 'version' => 'integer',
        ];
    }
}
