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
 * The numbered payslip of a finalized payroll item (gap-free series per tax year). Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $payroll_run_id
 * @property string $payroll_item_id
 * @property string $employee_id
 * @property string $number
 * @property string $month
 * @property int $net_paise
 * @property Carbon $created_at
 * @property-read PayrollItem $item
 */
class Payslip extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['payroll_run_id', 'payroll_item_id', 'employee_id', 'number', 'month', 'net_paise', 'created_at'];

    protected function casts(): array
    {
        return ['net_paise' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<PayrollItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }
}
