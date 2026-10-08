<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Default markup over cost used to suggest a selling rate, per product category (confidential).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $category
 * @property int $markup_bp
 * @property int $version
 */
class MarkupSetting extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['category', 'markup_bp'];

    protected function casts(): array
    {
        return ['markup_bp' => 'integer', 'version' => 'integer'];
    }
}
