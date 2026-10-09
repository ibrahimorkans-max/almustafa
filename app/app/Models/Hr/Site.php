<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A place where attendance may be marked (mill, godown, procurement point) with its geofence and,
 * optionally, the hours in which punches are expected.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string $latitude
 * @property string $longitude
 * @property int $radius_m
 * @property list<array{days: list<int>, from: string, to: string}>|null $time_windows
 * @property string $status
 * @property int $version
 */
class Site extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const KINDS = ['mill' => 'Mill', 'godown' => 'Godown', 'procurement_point' => 'Procurement point', 'other' => 'Other'];

    protected $fillable = ['code', 'name', 'kind', 'latitude', 'longitude', 'radius_m', 'time_windows', 'status'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'radius_m' => 'integer', 'time_windows' => 'array', 'version' => 'integer'];
    }

    /** Within the configured hours (PKT)? No windows = any time. Days are ISO 1 (Mon) … 7 (Sun). */
    public function isOpenAt(CarbonInterface $moment): bool
    {
        $windows = $this->time_windows ?? [];
        if ($windows === []) {
            return true;
        }
        $local = $moment->copy()->setTimezone('Asia/Karachi');
        $hm = $local->format('H:i');
        foreach ($windows as $w) {
            if (! in_array($local->isoWeekday(), array_map('intval', $w['days'] ?? []), true)) {
                continue;
            }
            $from = (string) ($w['from'] ?? '00:00');
            $to = (string) ($w['to'] ?? '23:59');
            // Windows crossing midnight (e.g. night shift 20:00–06:00).
            if ($from <= $to ? ($hm >= $from && $hm <= $to) : ($hm >= $from || $hm <= $to)) {
                return true;
            }
        }

        return false;
    }
}
