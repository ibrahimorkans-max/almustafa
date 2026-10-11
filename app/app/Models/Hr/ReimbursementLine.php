<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Accounts\ExpenseCategory;
use App\Models\StoredFile;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One expense of a claim with its receipt. Append-only: a wrong line means refusing the claim and
 * submitting a corrected one.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $reimbursement_id
 * @property string $expense_category_id
 * @property Carbon $spent_on
 * @property string $description
 * @property int $amount_paise
 * @property string|null $receipt_file_id
 * @property Carbon $created_at
 * @property-read ExpenseCategory $category
 * @property-read StoredFile|null $receipt
 */
class ReimbursementLine extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['reimbursement_id', 'expense_category_id', 'spent_on', 'description', 'amount_paise', 'receipt_file_id', 'created_at'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount_paise' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'receipt_file_id');
    }
}
