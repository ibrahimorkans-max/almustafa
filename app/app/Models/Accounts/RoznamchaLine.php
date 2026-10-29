<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a roznamcha page: cash received from (credit) or paid to (debit) a party or account.
 *
 * @property string $id
 * @property string $roznamcha_page_id
 * @property int $position
 * @property string|null $party_id
 * @property string|null $ledger_account_id
 * @property string|null $account_text
 * @property int $debit_paise
 * @property int $credit_paise
 * @property string|null $narration
 * @property string|null $reference
 * @property string|null $voucher_id
 * @property-read Party|null $party
 * @property-read LedgerAccount|null $ledgerAccount
 */
class RoznamchaLine extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['roznamcha_page_id', 'position', 'party_id', 'ledger_account_id', 'account_text', 'debit_paise', 'credit_paise', 'narration', 'reference'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'debit_paise' => 'integer', 'credit_paise' => 'integer'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
