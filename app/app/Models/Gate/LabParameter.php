<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One lab test a lot is checked against (App\Domain\Gate\LabStandards).
 *
 * @property string $id
 * @property string $entity_id
 * @property string|null $product_id null: every product
 * @property string $code
 * @property string $name_en
 * @property string $name_ur
 * @property string $unit
 * @property int $standard_bp
 * @property int $grace_bp
 * @property int $sort
 * @property string $status
 * @property Carbon $created_at
 * @property-read Product|null $product
 */
class LabParameter extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['product_id', 'code', 'name_en', 'name_ur', 'unit', 'standard_bp', 'grace_bp', 'sort', 'status'];

    protected function casts(): array
    {
        return ['standard_bp' => 'integer', 'grace_bp' => 'integer', 'sort' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
