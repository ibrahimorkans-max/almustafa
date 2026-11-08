<?php

declare(strict_types=1);

namespace App\Models\Usage;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a provider actually billed for a month (from its invoice), set against the usage estimated for it.
 *
 * @property string $id
 * @property string $provider
 * @property string|null $channel
 * @property Carbon $period
 * @property string $amount
 * @property string $currency
 * @property string|null $reference
 */
class ProviderExpense extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period' => 'date', 'amount' => 'decimal:6'];
    }
}
