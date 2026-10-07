<?php

declare(strict_types=1);

namespace App\Models\Production;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Product;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expected share of an output product per paddy variety (basis points of paddy input).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $paddy_product_id
 * @property string $output_product_id
 * @property int $expected_bp
 * @property int $tolerance_bp
 * @property string $status
 * @property-read Product $paddy
 * @property-read Product $output
 */
class OutturnStandard extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['paddy_product_id', 'output_product_id', 'expected_bp', 'tolerance_bp', 'status'];

    protected function casts(): array
    {
        return ['expected_bp' => 'integer', 'tolerance_bp' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function paddy(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'paddy_product_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function output(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }
}
