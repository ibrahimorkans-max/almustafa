<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The payroll of one entity for one month: draft → locked (attendance frozen) → calculated →
 * approved (a different person) → finalized (posted, payslips numbered, month locked for good).
 *
 * @property string $id
 * @property string $entity_id
 * @property string|null $number
 * @property string $month
 * @property string $status
 * @property int $calculation_no
 * @property int $lock_no
 * @property string|null $locked_by
 * @property Carbon|null $locked_at
 * @property string|null $calculated_by
 * @property Carbon|null $calculated_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $finalized_by
 * @property Carbon|null $finalized_at
 * @property string|null $voucher_id
 * @property array<string, int>|null $totals
 * @property string|null $note
 * @property int $version
 */
class PayrollRun extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const DRAFT = 'draft';

    public const LOCKED = 'locked';

    public const CALCULATED = 'calculated';

    public const APPROVED = 'approved';

    public const FINALIZED = 'finalized';

    public const STATUSES = [
        self::DRAFT => 'Draft', self::LOCKED => 'Attendance locked', self::CALCULATED => 'Calculated — to review',
        self::APPROVED => 'Approved', self::FINALIZED => 'Finalized',
    ];

    protected $fillable = ['month', 'note'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'calculation_no' => 'integer', 'lock_no' => 'integer', 'locked_at' => 'datetime', 'calculated_at' => 'datetime', 'approved_at' => 'datetime',
            'finalized_at' => 'datetime', 'totals' => 'array', 'version' => 'integer',
        ];
    }

    /** Attendance is frozen from "locked" on: later changes to that month are refused. */
    public function attendanceLocked(): bool
    {
        return $this->status !== self::DRAFT;
    }

    /** @return HasMany<PayrollItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /** @return HasMany<PayrollItem, $this> the items of the current calculation */
    public function currentItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class)->where('calculation_no', $this->calculation_no);
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** @return HasMany<PayrollPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
    }
}
