<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Security\RecordsEntryLocation;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Gate\GateEntry;
use App\Models\Masters\Party;
use App\Models\StoredFile;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An expense paid (or owed) or other income received, against a category. A draft (typed in, or
 * made by a recurring template) changes nothing in the books; posting makes its voucher.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $kind expense|income
 * @property string $expense_category_id
 * @property Carbon $entry_date
 * @property int $amount_paise
 * @property string $description
 * @property string|null $cash_ledger_id
 * @property string|null $party_id
 * @property string|null $receipt_file_id
 * @property string $status
 * @property string|null $recurring_expense_id
 * @property string|null $recurring_month
 * @property string|null $voucher_id
 * @property string|null $posted_by
 * @property Carbon|null $posted_at
 * @property string|null $cancel_reason
 * @property string|null $created_by
 * @property string|null $gate_entry_id the vehicle this charge belongs to
 * @property bool $capitalize_to_stock part of that lot's landed cost (stock value)
 * @property-read ExpenseCategory $category
 * @property-read LedgerAccount|null $cashLedger
 * @property-read Party|null $party
 * @property-read StoredFile|null $receipt
 * @property-read Voucher|null $voucher
 * @property array{lat: float, lng: float, accuracy_m: int|null, at: string}|null $captured_location where it was entered (EntryLocation)
 */
class Expense extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use RecordsEntryLocation;
    use TracksCreator;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT => 'Draft (not in the books)', self::POSTED => 'Posted', self::CANCELLED => 'Cancelled'];

    protected $fillable = [
        'number', 'kind', 'expense_category_id', 'entry_date', 'amount_paise', 'description', 'cash_ledger_id', 'party_id',
        'receipt_file_id', 'recurring_expense_id', 'recurring_month', 'gate_entry_id', 'capitalize_to_stock',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount_paise' => 'integer', 'posted_at' => 'datetime', 'version' => 'integer', 'capitalize_to_stock' => 'boolean'];
    }

    /** @return BelongsTo<GateEntry, $this> the vehicle this charge belongs to (freight, unloading…) */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function cashLedger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'cash_ledger_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'receipt_file_id');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
