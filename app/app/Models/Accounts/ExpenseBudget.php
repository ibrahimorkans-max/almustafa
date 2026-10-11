<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The amount planned for a category in one month; the reports compare it with the category's ledger.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $expense_category_id
 * @property string $month
 * @property int $amount_paise
 * @property-read ExpenseCategory $category
 */
class ExpenseBudget extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['expense_category_id', 'month', 'amount_paise'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
