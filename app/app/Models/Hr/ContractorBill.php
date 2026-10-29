<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A labour contractor's bill for a period, built from the attendance (man-days) or piece work of the
 * workers the contractor supplied, never typed in. Approved by a second person, it posts the labour
 * expense against the contractor's ledger and the tax withheld; it is paid through party payments.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $party_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property string $basis man_days|piece
 * @property int $gross_paise
 * @property int $sales_tax_bp
 * @property int $sales_tax_paise
 * @property int $wht_bp
 * @property int $wht_paise
 * @property int $payable_paise after tax withheld and deductions
 * @property int $deduction_paise
 * @property string|null $deduction_note
 * @property int $paid_paise
 * @property string $status draft|approved|rejected
 * @property string|null $note
 * @property string|null $prepared_by
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $decision_note
 * @property string|null $voucher_id
 * @property-read Party $party
 * @property-read Collection<int, ContractorBillPayment> $payments
 */
class ContractorBill extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const BASES = ['man_days' => 'Man-days from attendance', 'piece' => 'Piece work (bags, maunds …)',
        'vehicles' => 'Vehicles unloaded / loaded (rates from HR → Contractor rates)'];

    protected $fillable = [
        'number', 'party_id', 'period_from', 'period_to', 'basis', 'gross_paise', 'sales_tax_bp', 'sales_tax_paise', 'wht_bp', 'wht_paise',
        'payable_paise', 'note', 'prepared_by', 'deduction_paise', 'deduction_note',
    ];

    public const PAYMENT_STATUSES = ['unpaid' => 'Unpaid', 'partial' => 'Partly paid', 'paid' => 'Paid'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date', 'period_to' => 'date', 'gross_paise' => 'integer', 'sales_tax_bp' => 'integer', 'sales_tax_paise' => 'integer',
            'wht_bp' => 'integer', 'wht_paise' => 'integer', 'payable_paise' => 'integer', 'deduction_paise' => 'integer', 'paid_paise' => 'integer', 'approved_at' => 'datetime', 'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function balancePaise(): int
    {
        return max(0, $this->payable_paise - $this->paid_paise);
    }

    /** unpaid | partial | paid */
    public function paymentStatus(): string
    {
        return match (true) {
            $this->paid_paise <= 0 => 'unpaid',
            $this->paid_paise < $this->payable_paise => 'partial',
            default => 'paid',
        };
    }

    /** @return HasMany<ContractorBillPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(ContractorBillPayment::class)->orderBy('paid_on');
    }

    /** @return HasMany<ContractorBillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ContractorBillLine::class);
    }
}
