<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-off amount for one employee and month — a bonus (festival, performance, attendance), another
 * earning (arrears), a fine or another deduction (canteen …). Always with a reason; counted in
 * payroll only once a different person has approved it. Never silent, never edited after a decision.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string $month
 * @property string $type bonus|earning|fine|deduction
 * @property string $code
 * @property string $name
 * @property int $amount_paise
 * @property string $reason
 * @property string $status requested|approved|rejected
 * @property string|null $batch_id
 * @property string|null $requested_by
 * @property string|null $approved_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read Employee $employee
 */
class PayAdjustment extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const TYPES = ['bonus' => 'Bonus', 'earning' => 'Other earning', 'fine' => 'Fine', 'deduction' => 'Other deduction'];

    /** code => [name, type] offered on the screens; any other code is allowed too. */
    public const CODES = [
        'FESTIVAL' => ['Festival bonus (Eid)', 'bonus'], 'PERF' => ['Performance bonus', 'bonus'], 'ATT_BONUS' => ['Attendance bonus', 'bonus'],
        'ARREARS' => ['Arrears', 'earning'], 'FINE' => ['Fine', 'fine'], 'CANTEEN' => ['Canteen', 'deduction'], 'OTHER_DED' => ['Other deduction', 'deduction'],
    ];

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = ['employee_id', 'month', 'type', 'code', 'name', 'amount_paise', 'reason', 'batch_id', 'requested_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::REQUESTED];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'decided_at' => 'datetime', 'version' => 'integer'];
    }

    public function isEarning(): bool
    {
        return in_array($this->type, ['bonus', 'earning'], true);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
