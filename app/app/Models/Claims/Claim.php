<?php

declare(strict_types=1);

namespace App\Models\Claims;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\PurchaseBill;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A weight / quality dispute against a sales invoice or a purchase bill. Settled only by a
 * credit / debit note after a different person approves the amount.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $document_type
 * @property string $document_id
 * @property string $party_id
 * @property string|null $transporter_party_id
 * @property string $type
 * @property string $description
 * @property int $claimed_paise
 * @property int|null $settled_paise
 * @property list<string>|null $evidence
 * @property string $status
 * @property string|null $voucher_id
 * @property string|null $created_by
 * @property int $version
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property-read Party $party
 */
class Claim extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const SALES = 'sales_bill';

    public const PURCHASE = 'purchase_bill';

    /** @var array<string, string> */
    public const TYPES = ['weight_shortage' => 'Weight shortage', 'quality' => 'Quality', 'damage' => 'Damage / wet bags', 'other' => 'Other'];

    public const OPEN = 'open';

    public const APPROVED = 'approved';

    public const SETTLED = 'settled';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected $fillable = ['number', 'document_type', 'document_id', 'party_id', 'transporter_party_id', 'type', 'description', 'claimed_paise', 'evidence'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return [
            'claimed_paise' => 'integer', 'settled_paise' => 'integer', 'evidence' => 'array',
            'archived_at' => 'datetime', 'version' => 'integer',
        ];
    }

    public function document(): SalesBill|PurchaseBill|null
    {
        return $this->document_type === self::SALES
            ? SalesBill::query()->find($this->document_id)
            : PurchaseBill::query()->find($this->document_id);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'transporter_party_id');
    }

    /** @return HasMany<ClaimEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ClaimEvent::class)->orderBy('created_at');
    }
}
