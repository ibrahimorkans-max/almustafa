<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to the weighing modes of a company or a mill: what they were and became, who changed them and why.
 * Never changed or removed (the database refuses).
 *
 * @property int $id
 * @property string $config_id
 * @property string|null $customer_id
 * @property string|null $entity_id
 * @property string $level company | mill
 * @property string $action set | inherit
 * @property list<string>|null $before the modes on before
 * @property list<string>|null $after the modes on after (null: the mill follows its company again)
 * @property int $version
 * @property string|null $changed_by
 * @property string|null $reason
 * @property Carbon $created_at
 */
class WeighmentModeChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['config_id', 'customer_id', 'entity_id', 'level', 'action', 'before', 'after', 'version', 'changed_by', 'reason'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'changed_by');
    }
}
