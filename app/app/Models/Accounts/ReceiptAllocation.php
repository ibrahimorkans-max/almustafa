<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Sales\SalesBill;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which sales bills a receipt voucher settles.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $voucher_id
 * @property string $sales_bill_id
 * @property int $amount_paise
 * @property-read Voucher $voucher
 * @property-read SalesBill $bill
 */
class ReceiptAllocation extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['voucher_id', 'sales_bill_id', 'amount_paise'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<SalesBill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(SalesBill::class, 'sales_bill_id');
    }
}
