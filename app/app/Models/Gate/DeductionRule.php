<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Product;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a quality parameter above its free limit reduces payable weight or rate.
 *
 *  excess_weight: deduct (value − free) × factor/10000 percent of net weight
 *                 (factor 10000 = "1 % weight per 1 % excess moisture")
 *  rate_cut:      cut factor paisa per maund for every full 1 % above free
 *  per_bag:       deduct factor grams per bag (bag weight, "bardana")
 *
 * @property string $id
 * @property string $entity_id
 * @property string $product_id
 * @property string $parameter
 * @property string $method
 * @property int $free_limit_bp
 * @property int $factor
 * @property int|null $reject_above_bp
 * @property string $status
 * @property-read Product $product
 */
class DeductionRule extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const PARAMETERS = ['moisture', 'foreign_matter', 'broken', 'damaged', 'immature', 'chalky', 'bags'];

    public const METHODS = ['excess_weight', 'rate_cut', 'per_bag'];

    protected $fillable = ['product_id', 'parameter', 'method', 'free_limit_bp', 'factor', 'reject_above_bp', 'status'];

    protected function casts(): array
    {
        return ['free_limit_bp' => 'integer', 'factor' => 'integer', 'reject_above_bp' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
