<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Models\Masters\PartyBankAccount;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money leaving the mill. Pays only into the party's approved bank account (frozen at request time);
 * released by a different person (two people above the limit); release posts the payment voucher.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $party_id
 * @property string $mode
 * @property string $from_ledger_id
 * @property int $amount_paise
 * @property Carbon $payment_date
 * @property string|null $party_bank_account_id
 * @property array{holder: string, ifsc: string, last4: string, bank_name: string|null}|null $payee
 * @property string|null $payee_account_number
 * @property string $status
 * @property string|null $approval_request_id
 * @property string|null $voucher_id
 * @property string|null $bank_file_id
 * @property string|null $reference
 * @property string|null $narration
 * @property string|null $created_by
 * @property int $version
 * @property-read Party $party
 * @property-read LedgerAccount $fromLedger
 * @property-read PartyBankAccount|null $bankAccount
 */
class Payment extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const MODES = ['neft' => 'NEFT', 'rtgs' => 'RTGS', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash'];

    public const BANK_MODES = ['neft', 'rtgs', 'upi'];

    public const PENDING = 'pending';

    public const FIRST_APPROVED = 'first_approved';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const REVERSED = 'reversed';

    protected $fillable = [
        'number', 'party_id', 'mode', 'from_ledger_id', 'amount_paise', 'payment_date', 'party_bank_account_id',
        'payee', 'payee_account_number', 'reference', 'narration',
    ];

    protected $hidden = ['payee_account_number'];

    /** @var list<string> */
    protected array $auditMasked = ['payee_account_number'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer', 'payment_date' => 'date', 'payee' => 'array',
            'payee_account_number' => 'encrypted', 'version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::FIRST_APPROVED], true);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function fromLedger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'from_ledger_id');
    }

    /** @return BelongsTo<PartyBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(PartyBankAccount::class, 'party_bank_account_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
