<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Quality measurements of a sample (append-only; a re-test supersedes). Values in basis
 * points: 1750 = 17.50 %. The accept / reject decision is a separate gate event by someone
 * holding `lab.decide`.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $gate_entry_id
 * @property string|null $sample_code
 * @property int|null $moisture_bp
 * @property int|null $foreign_matter_bp
 * @property int|null $broken_bp
 * @property int|null $damaged_bp
 * @property int|null $immature_bp
 * @property int|null $chalky_bp
 * @property string|null $grade
 * @property string|null $remarks
 * @property string|null $supersedes_id
 * @property string|null $device_id
 * @property string|null $created_by
 * @property Carbon $tested_at
 * @property array<string, int>|null $results code => basis points, for the configured lab tests
 * @property list<array<string, mixed>>|null $evaluation each result against its limits (LabStandards), as read when recorded
 */
class LabTest extends Model
{
    use AppendOnly;
    use Auditable;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    /** Parameters measured in basis points, keyed like deduction rule parameters. */
    public const PARAMETERS = [
        'moisture' => 'moisture_bp',
        'foreign_matter' => 'foreign_matter_bp',
        'broken' => 'broken_bp',
        'damaged' => 'damaged_bp',
        'immature' => 'immature_bp',
        'chalky' => 'chalky_bp',
    ];

    protected $fillable = [
        'gate_entry_id', 'sample_code', 'moisture_bp', 'foreign_matter_bp', 'broken_bp', 'damaged_bp',
        'immature_bp', 'chalky_bp', 'grade', 'remarks', 'supersedes_id', 'device_id', 'tested_at', 'results', 'evaluation',
    ];

    protected function casts(): array
    {
        return [
            'moisture_bp' => 'integer', 'foreign_matter_bp' => 'integer', 'broken_bp' => 'integer',
            'damaged_bp' => 'integer', 'immature_bp' => 'integer', 'chalky_bp' => 'integer',
            'tested_at' => 'datetime', 'created_at' => 'datetime', 'results' => 'array', 'evaluation' => 'array',
        ];
    }

    /** @return array<string, int> parameter => basis points, only measured ones (the configured lab tests and the older fixed columns) */
    public function measurements(): array
    {
        $out = array_map('intval', (array) ($this->results ?? []));
        foreach (self::PARAMETERS as $parameter => $column) {
            if ($this->getAttribute($column) !== null) {
                $out[$parameter] = (int) $this->getAttribute($column);
            }
        }

        return $out;
    }
}
