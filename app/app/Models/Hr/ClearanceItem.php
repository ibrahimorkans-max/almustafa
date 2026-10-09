<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string $item
 * @property Carbon|null $done_at
 * @property string|null $done_by
 * @property string|null $note
 */
class ClearanceItem extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    /** Default exit checklist; HR can add items per employee. */
    public const DEFAULT_ITEMS = [
        'ID card and keys returned',
        'Tools, uniform and safety gear returned',
        'Advances and loans settled',
        'ERP login and devices handed over',
        'Handover to the replacement / supervisor',
    ];

    protected $table = 'employee_clearance_items';

    protected $fillable = ['employee_id', 'item', 'done_at', 'done_by', 'note'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime', 'version' => 'integer'];
    }
}
