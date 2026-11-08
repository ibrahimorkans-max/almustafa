<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Gate\WeighModes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which of the four weighing modes a company (customer_id: its defaults) or one mill (entity_id: its own setting)
 * may use. Set by the SaaS owner only (App\Domain\Gate\WeighModes::configure); every change is in
 * weighment_mode_changes.
 *
 * @property string $id
 * @property string|null $customer_id
 * @property string|null $entity_id
 * @property bool $lab_before_slip
 * @property bool $no_lab
 * @property bool $weigh_first_lab_later
 * @property bool $lab_first
 * @property int $version
 */
class WeighmentModeConfig extends Model
{
    use HasUuids;

    protected $fillable = ['customer_id', 'entity_id', ...WeighModes::ALL, 'version', 'updated_by'];

    protected function casts(): array
    {
        return array_fill_keys(WeighModes::ALL, 'boolean') + ['version' => 'integer'];
    }

    /** @return list<string> the modes switched on */
    public function enabled(): array
    {
        return array_values(array_filter(WeighModes::ALL, fn (string $m) => (bool) $this->getAttribute($m)));
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
