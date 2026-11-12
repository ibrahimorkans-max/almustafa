<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Inventory\StockEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One old record brought in from the mill's paper registers (App\Domain\Accounts\PaperRecords), with the voucher and
 * stock movement it was posted as. Never edited; a wrong one is cancelled (its postings reversed) and entered again.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $kind
 * @property Carbon $record_date
 * @property string|null $book_ref
 * @property string|null $paper_number
 * @property string|null $party_id
 * @property string|null $description
 * @property int|null $amount_paise
 * @property string|null $quantity_kg
 * @property array<string, mixed>|null $details
 * @property string|null $voucher_id
 * @property string|null $stock_entry_id
 * @property string|null $import_batch
 * @property string|null $entered_by
 * @property Carbon $created_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 */
class PaperRecord extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['id', 'kind', 'record_date', 'book_ref', 'paper_number', 'party_id', 'description', 'amount_paise', 'quantity_kg',
        'details', 'voucher_id', 'stock_entry_id', 'import_batch', 'entered_by', 'created_at'];

    protected function casts(): array
    {
        return ['record_date' => 'date', 'details' => 'array', 'amount_paise' => 'integer', 'created_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withoutGlobalScope('not_archived');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<StockEntry, $this> */
    public function stockEntry(): BelongsTo
    {
        return $this->belongsTo(StockEntry::class);
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'entered_by');
    }
}
