<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A working shift (IST). A shift whose end is earlier than its start crosses midnight and belongs
 * to the day it starts.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $start_time HH:MM
 * @property string $end_time HH:MM
 * @property int $break_minutes
 * @property int $grace_late_minutes
 * @property int $grace_early_minutes
 * @property int $full_day_minutes
 * @property int $half_day_minutes
 * @property int $overtime_after_minutes
 * @property list<int> $weekly_offs
 * @property string $status
 */
class Shift extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    protected $fillable = [
        'code', 'name', 'start_time', 'end_time', 'break_minutes', 'grace_late_minutes', 'grace_early_minutes',
        'full_day_minutes', 'half_day_minutes', 'overtime_after_minutes', 'weekly_offs', 'status',
    ];

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer', 'grace_late_minutes' => 'integer', 'grace_early_minutes' => 'integer',
            'full_day_minutes' => 'integer', 'half_day_minutes' => 'integer', 'overtime_after_minutes' => 'integer',
            'weekly_offs' => 'array', 'version' => 'integer',
        ];
    }

    public function crossesMidnight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    /**
     * Scheduled start and end (IST) of the shift that starts on the given date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowFor(CarbonImmutable $date): array
    {
        $day = $date->setTimezone('Asia/Kolkata')->startOfDay();
        [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', $this->end_time));
        $start = $day->setTime($sh, $sm);
        $end = $day->setTime($eh, $em);
        if ($this->crossesMidnight()) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    /** Length of the shift in minutes (without break). */
    public function scheduledMinutes(): int
    {
        [$start, $end] = $this->windowFor(CarbonImmutable::parse('2026-01-05', 'Asia/Kolkata'));

        return (int) $start->diffInMinutes($end, true) - $this->break_minutes;
    }

    public function isWeeklyOff(CarbonImmutable $date): bool
    {
        return in_array($date->setTimezone('Asia/Kolkata')->isoWeekday(), array_map('intval', $this->weekly_offs ?? []), true);
    }
}
