<?php

declare(strict_types=1);

namespace App\Models\DailyWages;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Models\LoginAccount;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day of casual work by a labour group (or a single worker) and what it is paid:
 * gross = quantity × rate (× workers when the rate is per person), + extra − deduction = net.
 * Draft → posted (expense booked, still owed) → paid; or draft → paid at once; or cancelled.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property Carbon $work_date
 * @property string|null $group_id
 * @property string|null $worker_name
 * @property string $category_id
 * @property string $unit
 * @property int $workers
 * @property string $quantity
 * @property int $rate_paise
 * @property int $gross_paise
 * @property int $extra_paise
 * @property int $deduction_paise
 * @property int $net_paise
 * @property string|null $worker_names
 * @property string|null $description
 * @property string $status
 * @property string|null $voucher_id
 * @property string|null $payment_voucher_id
 * @property string|null $paid_from_ledger_id
 * @property string|null $posted_by
 * @property Carbon|null $posted_at
 * @property string|null $paid_by
 * @property Carbon|null $paid_at
 * @property string|null $cancel_reason
 * @property string|null $created_by
 * @property-read DailyWageGroup|null $group
 * @property-read DailyWageCategory $category
 * @property-read LedgerAccount|null $paidFrom
 * @property-read Voucher|null $voucher
 * @property-read LoginAccount|null $creator
 */
class DailyWageSheet extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT => 'Draft', self::POSTED => 'Booked, not paid yet', self::PAID => 'Paid', self::CANCELLED => 'Cancelled'];

    protected $fillable = [
        'work_date', 'group_id', 'worker_name', 'category_id', 'unit', 'workers', 'quantity', 'rate_paise',
        'gross_paise', 'extra_paise', 'deduction_paise', 'net_paise', 'worker_names', 'description',
    ];

    protected $attributes = ['status' => self::DRAFT, 'extra_paise' => 0, 'deduction_paise' => 0];

    protected function casts(): array
    {
        return [
            'work_date' => 'date', 'workers' => 'integer', 'quantity' => 'decimal:3', 'rate_paise' => 'integer', 'gross_paise' => 'integer',
            'extra_paise' => 'integer', 'deduction_paise' => 'integer', 'net_paise' => 'integer', 'posted_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DailyWageGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(DailyWageGroup::class, 'group_id');
    }

    /** @return BelongsTo<DailyWageCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DailyWageCategory::class, 'category_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'paid_from_ledger_id');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'created_by');
    }

    /** Who the work was done by: the group (and its leader) or the single worker. */
    public function who(): string
    {
        return $this->group !== null ? $this->group->label() : (string) $this->worker_name;
    }
}
