<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of an imported bank statement and the book entry it was matched to.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $ledger_account_id
 * @property Carbon $txn_date
 * @property string $description
 * @property string|null $reference
 * @property int $debit_paise
 * @property int $credit_paise
 * @property string $fingerprint
 * @property string|null $voucher_line_id
 * @property string|null $match_method
 * @property string|null $matched_by
 * @property Carbon|null $matched_at
 * @property-read VoucherLine|null $voucherLine
 */
class BankStatementLine extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;

    protected $fillable = ['ledger_account_id', 'txn_date', 'description', 'reference', 'debit_paise', 'credit_paise', 'fingerprint'];

    protected function casts(): array
    {
        return ['txn_date' => 'date', 'debit_paise' => 'integer', 'credit_paise' => 'integer', 'matched_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<VoucherLine, $this> */
    public function voucherLine(): BelongsTo
    {
        return $this->belongsTo(VoucherLine::class);
    }
}
