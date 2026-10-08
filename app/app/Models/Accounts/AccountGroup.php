<?php

declare(strict_types=1);

namespace App\Models\Accounts;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Group in the chart of accounts (Tally-style tree). The nature decides the normal side and
 * whether it belongs to the balance sheet or the profit and loss account.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string|null $parent_id
 * @property string $nature
 * @property bool $is_system
 * @property int $sort
 * @property-read AccountGroup|null $parent
 */
class AccountGroup extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const NATURES = ['asset', 'liability', 'income', 'expense'];

    protected $fillable = ['entity_id', 'code', 'name', 'parent_id', 'nature', 'is_system', 'sort'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'sort' => 'integer'];
    }

    /** @return BelongsTo<AccountGroup, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Assets and expenses grow on the debit side. */
    public function isDebitNature(): bool
    {
        return in_array($this->nature, ['asset', 'expense'], true);
    }
}
