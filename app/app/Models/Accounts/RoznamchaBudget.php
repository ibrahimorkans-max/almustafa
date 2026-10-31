<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day's own roznamcha budget (cash that may be paid out that day); other days use the usual amount
 * (App\Domain\Accounts\RoznamchaBudgets).
 *
 * @property string $id
 * @property string $entity_id
 * @property Carbon $budget_date
 * @property int $amount_paise
 * @property string|null $note
 * @property string|null $created_by
 */
class RoznamchaBudget extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['budget_date', 'amount_paise', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['budget_date' => 'date', 'amount_paise' => 'integer'];
    }
}
