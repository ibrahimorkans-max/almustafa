<?php

declare(strict_types=1);

namespace App\Models\Production;

use App\Domain\Audit\Auditable;
use App\Domain\Sync\FeedsSync;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Models\Masters\Product;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A milling run of one paddy variety. input_kg / output_kg are projections of its logs; the
 * yield is frozen into yield_snapshot when the batch is closed.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $paddy_product_id
 * @property string|null $line
 * @property string $status
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property string $input_kg
 * @property string $output_kg
 * @property array<string, mixed>|null $yield_snapshot
 * @property string|null $notes
 * @property int $version
 * @property-read Product $paddy
 */
class ProductionBatch extends Model
{
    use Auditable;
    use BelongsToEntity;
    use FeedsSync;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const SYNC_TYPE = 'production_batch';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public function toSyncArray(LoginAccount $viewer): array
    {
        return [
            'id' => $this->id, 'version' => $this->version, 'number' => $this->number,
            'paddy_product_id' => $this->paddy_product_id, 'line' => $this->line, 'status' => $this->status,
            'opened_at' => $this->opened_at->toIso8601ZuluString('millisecond'),
            'closed_at' => $this->closed_at?->toIso8601ZuluString('millisecond'),
            'input_kg' => $this->input_kg, 'output_kg' => $this->output_kg,
        ];
    }

    protected $fillable = ['number', 'paddy_product_id', 'line', 'opened_at', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::OPEN, 'input_kg' => '0.000', 'output_kg' => '0.000'];

    /** @var list<string> */
    protected array $auditIgnored = ['input_kg', 'output_kg'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime', 'closed_at' => 'datetime', 'archived_at' => 'datetime', 'input_kg' => 'decimal:3', 'output_kg' => 'decimal:3',
            'yield_snapshot' => 'array', 'version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return BelongsTo<Product, $this> */
    public function paddy(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'paddy_product_id');
    }

    /** @return HasMany<ProductionLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ProductionLog::class, 'batch_id')->orderBy('period_start')->orderBy('created_at');
    }
}
