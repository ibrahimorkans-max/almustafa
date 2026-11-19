<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A charge on a quick bill: vehicle rent, labour, anything typed — added to it (the party pays) or taken off it.
 *
 * @property string $id
 * @property string $quick_bill_id
 * @property string $label
 * @property string $effect add | less
 * @property int $amount_paise
 */
class QuickBillCharge extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }
}
