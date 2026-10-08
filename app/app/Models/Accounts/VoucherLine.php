<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $voucher_id
 * @property int $line_no
 * @property string $ledger_account_id
 * @property int $dr_paise
 * @property int $cr_paise
 * @property Carbon $voucher_date
 * @property string|null $bill_id
 * @property string|null $memo
 * @property-read Voucher $voucher
 * @property-read LedgerAccount $account
 */
class VoucherLine extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = [
        'entity_id', 'voucher_id', 'line_no', 'ledger_account_id', 'dr_paise', 'cr_paise', 'voucher_date', 'bill_id', 'memo', 'created_at',
    ];

    protected function casts(): array
    {
        return ['dr_paise' => 'integer', 'cr_paise' => 'integer', 'line_no' => 'integer', 'voucher_date' => 'date', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
