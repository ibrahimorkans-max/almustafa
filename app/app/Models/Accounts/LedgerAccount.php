<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use App\Support\Values\PakistanIds;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A postable account. Party ledgers are created on first use; system accounts are seeded per
 * entity and found by system_key; mill bank accounts keep the number encrypted and show it masked.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $group_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string|null $system_key
 * @property string|null $party_id
 * @property string|null $bank_account_number
 * @property string|null $bank_account_last4
 * @property string|null $bank_code
 * @property string|null $bank_name
 * @property string $status
 * @property int $version
 * @property-read AccountGroup $group
 * @property-read Party|null $party
 */
class LedgerAccount extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const KINDS = ['general', 'party', 'bank', 'cash', 'system'];

    protected $fillable = [
        'entity_id', 'group_id', 'code', 'name', 'kind', 'system_key', 'party_id',
        'bank_account_number', 'bank_name', 'status',
    ];

    protected $hidden = ['bank_account_number'];

    /** @var list<string> */
    protected array $auditMasked = ['bank_account_number'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'general', 'status' => 'active'];

    protected function casts(): array
    {
        return ['bank_account_number' => 'encrypted', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (LedgerAccount $account): void {
            if ($account->bank_account_number !== null) {
                // The mill's account is held as its IBAN; the bank code is part of it.
                $account->bank_account_number = PakistanIds::normalizeIban($account->bank_account_number);
                $account->bank_account_last4 = substr((string) $account->bank_account_number, -4);
                $account->bank_code = strlen((string) $account->bank_account_number) === 24 ? PakistanIds::bankCodeOfIban((string) $account->bank_account_number) : null;
            }
        });
    }

    /** @return BelongsTo<AccountGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'group_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function displayName(): string
    {
        return $this->kind === 'bank' && $this->bank_account_last4
            ? "{$this->name} (…{$this->bank_account_last4})"
            : $this->name;
    }
}
