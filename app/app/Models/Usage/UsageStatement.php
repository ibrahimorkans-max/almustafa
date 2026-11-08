<?php

declare(strict_types=1);

namespace App\Models\Usage;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A company's service-usage charge for one month, as worked out when it was issued (kept unchanged).
 *
 * @property string $id
 * @property string $customer_id
 * @property Carbon $period
 * @property list<array<string, mixed>> $lines
 * @property string $subtotal
 * @property string $tax
 * @property string $total
 * @property string $currency
 */
class UsageStatement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period' => 'date', 'lines' => 'array', 'subtotal' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
    }
}
