<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A pay head (Basic, HRA, PF, ...). Calculation rules arrive in HR phase 6; phase 1 stores amounts.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $kind earning|deduction
 * @property bool $is_statutory
 * @property string $status
 * @property int $sort
 * @property int $version
 */
class SalaryComponent extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const EARNING = 'earning';

    public const DEDUCTION = 'deduction';

    /** Installed in every entity: [code, name, kind, statutory, sort]. */
    public const DEFAULTS = [
        ['BASIC', 'Basic', 'earning', false, 10],
        ['HRA', 'House rent allowance', 'earning', false, 20],
        ['CONV', 'Conveyance', 'earning', false, 30],
        ['SPECIAL', 'Special allowance', 'earning', false, 40],
        ['PF', 'Provident fund (employee)', 'deduction', true, 110],
        ['ESI', 'ESI (employee)', 'deduction', true, 120],
        ['PT', 'Professional tax', 'deduction', true, 130],
        ['TDS', 'Income tax (TDS)', 'deduction', true, 140],
        ['ADV', 'Advance recovery', 'deduction', false, 150],
    ];

    protected $fillable = ['code', 'name', 'kind', 'is_statutory', 'status', 'sort'];

    protected function casts(): array
    {
        return ['is_statutory' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }
}
