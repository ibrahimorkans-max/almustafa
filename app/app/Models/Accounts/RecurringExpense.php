<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly expense (rent, electricity, guards …) that drafts an Expense on its day each month for
 * accounts to check and post. The amount is the usual one; accounts correct it on the draft.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $name
 * @property string $expense_category_id
 * @property int $amount_paise
 * @property int $day_of_month
 * @property string|null $pay_from_ledger_id
 * @property string|null $party_id
 * @property string $starts_month
 * @property string|null $ends_month
 * @property bool $active
 * @property-read ExpenseCategory $category
 * @property-read LedgerAccount|null $payFrom
 * @property-read Party|null $party
 */
class RecurringExpense extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['name', 'expense_category_id', 'amount_paise', 'day_of_month', 'pay_from_ledger_id', 'party_id', 'starts_month', 'ends_month', 'active'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'day_of_month' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function payFrom(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'pay_from_ledger_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
