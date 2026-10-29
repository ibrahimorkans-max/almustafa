<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Saas\DataVisibility;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How far back a customer sees its own records, set by the product owner: for a plan (every customer
 * on it, unless the customer has its own rule) or for one customer; for all modules or one. Records
 * are never deleted for it (DataVisibility).
 *
 * @property string $id
 * @property string|null $customer_id
 * @property string|null $plan_id
 * @property string $module
 * @property int|null $duration
 * @property string $duration_unit days | months
 * @property Carbon|null $starts_on
 * @property Carbon|null $expires_on
 * @property int $grace_days
 * @property string $status active | inactive
 * @property Carbon|null $override_until
 * @property string|null $override_reason
 * @property-read Customer|null $customer
 * @property-read Plan|null $plan
 */
class DataVisibilityRule extends Model
{
    use Auditable;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = ['customer_id', 'plan_id', 'module', 'duration', 'duration_unit', 'starts_on', 'expires_on', 'grace_days', 'status', 'override_until', 'override_reason'];

    /** @var array<string, mixed> */
    protected $attributes = ['module' => 'all', 'duration_unit' => 'days', 'grace_days' => 0, 'status' => 'active'];

    protected function casts(): array
    {
        return ['duration' => 'integer', 'grace_days' => 'integer', 'starts_on' => 'date', 'expires_on' => 'date', 'override_until' => 'date', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        // A change shows at once (the rules are cached per customer).
        static::saved(fn () => DataVisibility::forget());
        static::deleted(fn () => DataVisibility::forget());
    }

    /** "Last 90 days", "Last 6 months", "No limit". */
    public function windowLabel(): string
    {
        return $this->duration === null ? 'No limit' : 'Last '.$this->duration.' '.($this->duration === 1 ? rtrim($this->duration_unit, 's') : $this->duration_unit);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
