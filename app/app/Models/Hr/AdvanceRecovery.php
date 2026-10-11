<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recovery of an advance or loan (a payroll deduction or a cash repayment). Append-only.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $advance_id
 * @property int $amount_paise
 * @property Carbon $recovered_on
 * @property string $source payroll|cash
 * @property string|null $source_id
 * @property string|null $voucher_id
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Advance $advance
 */
class AdvanceRecovery extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['advance_id', 'amount_paise', 'recovered_on', 'source', 'source_id', 'voucher_id', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'recovered_on' => 'date', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Advance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(Advance::class);
    }
}
