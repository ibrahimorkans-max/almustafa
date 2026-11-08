<?php

declare(strict_types=1);

namespace App\Models\Usage;

use Illuminate\Database\Eloquent\Model;

/**
 * Something learned after a usage was recorded: its delivery report, the provider's actual cost, or that it was
 * reconciled with the provider's bill. Added beside the usage, never changed.
 *
 * @property int $id
 * @property string $service_usage_id
 * @property string $kind delivery | actual_cost | reconciled
 * @property string|null $status
 * @property string|null $amount
 */
class ServiceUsageUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'amount' => 'decimal:6'];
    }
}
