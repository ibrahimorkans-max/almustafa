<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a quick bill: goods out (the mill gives), in (the mill takes) or a service (milling, weighing), with its
 * weights and rate per maund (40 kg).
 *
 * @property string $id
 * @property string $quick_bill_id
 * @property int $line_no
 * @property string $direction out | in | service
 * @property string|null $product_id
 * @property string $description
 * @property string|null $variety
 * @property string|null $godown_id
 * @property int|null $bags
 * @property string $gross_kg
 * @property string $tare_kg
 * @property string $deduction_per_bag_kg
 * @property string $other_deduction_kg
 * @property string $net_kg
 * @property int $rate_paise_per_maund
 * @property int $amount_paise
 * @property-read Product|null $product
 * @property-read Godown|null $godown
 */
class QuickBillLine extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'bags' => 'integer', 'rate_paise_per_maund' => 'integer', 'amount_paise' => 'integer'];
    }

    /** All the weight taken off: the bags' deduction and the other deduction. */
    public function deductionKg(): string
    {
        return bcadd(bcmul((string) ($this->bags ?? 0), $this->deduction_per_bag_kg, 3), $this->other_deduction_kg, 3);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Godown, $this> */
    public function godown(): BelongsTo
    {
        return $this->belongsTo(Godown::class);
    }
}
