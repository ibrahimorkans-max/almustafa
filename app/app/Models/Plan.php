<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A SaaS plan: the modules a customer may use, its limits (null = unlimited) and the monthly price.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $price_paise_monthly
 * @property int|null $max_mills
 * @property int|null $max_users
 * @property int|null $max_vehicles_month
 * @property list<string> $modules
 * @property bool $active
 * @property int $sort
 * @property Carbon $created_at
 */
class Plan extends Model
{
    use Auditable;
    use HasUuids;

    protected $fillable = ['code', 'name', 'description', 'price_paise_monthly', 'max_mills', 'max_users', 'max_vehicles_month', 'modules', 'active', 'sort'];

    protected function casts(): array
    {
        return [
            'price_paise_monthly' => 'integer', 'max_mills' => 'integer', 'max_users' => 'integer', 'max_vehicles_month' => 'integer',
            'modules' => 'array', 'active' => 'boolean', 'sort' => 'integer',
        ];
    }
}
