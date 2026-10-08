<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bulk-transfer file for the bank portal. The content is regenerated from its payments on
 * download; sha256 proves it has not changed since it was first produced.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $ledger_account_id
 * @property int $payment_count
 * @property int $total_paise
 * @property string $sha256
 * @property-read LedgerAccount $bank
 */
class BankFile extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['number', 'ledger_account_id', 'payment_count', 'total_paise', 'sha256'];

    protected function casts(): array
    {
        return ['payment_count' => 'integer', 'total_paise' => 'integer'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('number');
    }
}
