<?php

declare(strict_types=1);

namespace App\Models\Usage;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One price of an outside service, from a date (System → Service prices). A later price is a new row; the old one
 * stays, so every usage keeps the price it was costed at. customer_id: a company's own price, else everyone's.
 *
 * @property string $id
 * @property string|null $customer_id
 * @property string $channel
 * @property string $provider '*' for any provider of the channel
 * @property string $service '*' for any service
 * @property string $model pay_per_use | fixed_package | actual | custom
 * @property string $unit_price
 * @property string|null $charge_price
 * @property string $currency
 * @property Carbon $effective_from
 * @property int $free_allowance
 * @property list<array{from: int, price: string}>|null $tiers
 * @property string $tax_percent
 * @property string $monthly_fee
 * @property int|null $monthly_quota
 */
class ServicePrice extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const MODELS = ['pay_per_use' => 'Pay per use', 'fixed_package' => 'Fixed monthly package', 'actual' => "Provider's actual cost", 'custom' => 'Custom company price'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'tiers' => 'array', 'free_allowance' => 'integer', 'monthly_quota' => 'integer',
            'unit_price' => 'decimal:6', 'charge_price' => 'decimal:6', 'tax_percent' => 'decimal:3', 'monthly_fee' => 'decimal:2'];
    }
}
