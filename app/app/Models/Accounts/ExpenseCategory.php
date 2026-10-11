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
 * An expense or income category as people name it (fuel, travel, electricity, scrap sales …), each
 * posted to one ledger. Staff claims, recurring expenses and budgets use categories; the books and
 * the profit and loss stay on the ledgers.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $kind expense|income
 * @property string $ledger_account_id
 * @property bool $claimable
 * @property string $status
 * @property-read LedgerAccount $ledger
 */
class ExpenseCategory extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    /** code => [name, kind, ledger code, ledger name, group, claimable] installed for every mill. */
    public const DEFAULTS = [
        'TRAVEL' => ['Travel and conveyance', 'expense', 'EXP-TRAVEL', 'Travelling and conveyance', 'INDIRECT_EXPENSES', true],
        'FUEL' => ['Fuel (vehicles, generator)', 'expense', 'EXP-FUEL', 'Fuel and lubricants', 'DIRECT_EXPENSES', true],
        'MEALS' => ['Meals and refreshments', 'expense', 'EXP-MEALS', 'Staff welfare and refreshments', 'INDIRECT_EXPENSES', true],
        'REPAIRS' => ['Repairs and maintenance', 'expense', 'EXP-REPAIRS', 'Repairs and maintenance', 'DIRECT_EXPENSES', true],
        'STATIONERY' => ['Printing and stationery', 'expense', 'EXP-STATIONERY', 'Printing and stationery', 'INDIRECT_EXPENSES', true],
        'MOBILE' => ['Mobile and internet', 'expense', 'EXP-COMMS', 'Communication', 'INDIRECT_EXPENSES', true],
        'ELECTRICITY' => ['Electricity', 'expense', 'EXP-POWER', 'Electricity (mill)', 'DIRECT_EXPENSES', false],
        'RENT' => ['Rent', 'expense', 'EXP-RENT', 'Rent', 'INDIRECT_EXPENSES', false],
        'MISC' => ['Other expenses', 'expense', 'EXP-MISC', 'Miscellaneous expenses', 'INDIRECT_EXPENSES', true],
        'SCRAP' => ['Scrap and bag sales', 'income', 'INC-SCRAP', 'Scrap and empty bag sales', 'INDIRECT_INCOMES', false],
    ];

    protected $fillable = ['code', 'name', 'kind', 'ledger_account_id', 'claimable', 'status'];

    protected function casts(): array
    {
        return ['claimable' => 'boolean', 'version' => 'integer'];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
