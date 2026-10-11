<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\BlindIndex;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use App\Support\Values\PakistanIds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Created or changed ONLY by the PartyBankAccountChange approval action (maker-checker),
 * never directly from a form or a device. Not part of device sync.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $party_id
 * @property string $account_holder
 * @property string $account_number
 * @property string $account_number_index
 * @property string $account_last4
 * @property string|null $bank_code
 * @property string|null $bank_name
 * @property string|null $branch
 * @property string|null $raast_id
 * @property bool $is_primary
 * @property string $status
 * @property string|null $approval_request_id
 * @property int $version
 * @property Carbon|null $archived_at
 * @property-read Party $party
 */
class PartyBankAccount extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = [
        'entity_id', 'party_id', 'account_holder', 'account_number', 'bank_name', 'branch', 'raast_id', 'is_primary', 'status',
    ];

    protected $hidden = ['account_number', 'account_number_index', 'raast_id'];

    /** @var list<string> */
    protected array $auditMasked = ['account_number', 'raast_id'];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'raast_id' => 'encrypted',
            'is_primary' => 'boolean',
            'version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('not_archived', fn (Builder $q) => $q->whereNull('party_bank_accounts.archived_at'));

        static::saving(function (PartyBankAccount $account): void {
            // The account number is the IBAN (PK.. 24 characters); the bank code is part of it.
            $number = (string) PakistanIds::normalizeIban($account->account_number);
            $account->account_number = $number;
            $account->account_number_index = (string) BlindIndex::of($number, 'bank_account');
            $account->account_last4 = substr($number, -4);
            $account->bank_code = strlen($number) === 24 ? PakistanIds::bankCodeOfIban($number) : null;
        });
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function maskedNumber(): string
    {
        return 'XXXX'.$this->account_last4;
    }
}
