<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One punch exactly as it arrived, with the evidence and the server's verdict. Append-only: a
 * supervisor's later decision on a flagged punch is a separate record (HR phase 3).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $employee_id
 * @property string $kind in|out
 * @property string $source pwa|app|import|manual
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property Carbon|null $estimated_at
 * @property string $time_check verified|unverified|changed
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $accuracy_m
 * @property string|null $site_id
 * @property int|null $distance_m
 * @property string|null $selfie_sha256
 * @property string|null $device_id
 * @property string|null $login_account_id
 * @property string $client_event_id
 * @property string $status valid|flagged|rejected
 * @property list<string> $reasons
 * @property array<string, mixed>|null $signals
 * @property Carbon $created_at
 * @property-read Employee $employee
 * @property-read Site|null $site
 */
class AttendanceLog extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public const VALID = 'valid';

    public const FLAGGED = 'flagged';

    public const REJECTED = 'rejected';

    /** Plain words for every reason code, shown to the employee and to HR. */
    public const REASONS = [
        'no_employee' => 'This login is not linked to an employee record',
        'employee_not_active' => 'The employee is not active',
        'no_consent' => 'The attendance notice has not been accepted',
        'device_clock_changed' => 'The phone clock was changed between the punch and the upload',
        'future_time' => 'The punch time is in the future',
        'device_not_approved' => 'This phone is not approved for attendance yet',
        'outside_geofence' => 'Outside every work site',
        'outside_time_window' => 'Outside the site\'s working hours',
        'low_accuracy' => 'The location was not precise enough',
        'no_location' => 'No location was received',
        'time_unverified' => 'The punch time could not be checked (phone or app restarted before upload)',
        'no_selfie' => 'No photo was taken',
        'sequence' => 'Two punches of the same kind in a row',
        'late_upload' => 'Uploaded much later than it was taken',
    ];

    protected $fillable = [
        'employee_id', 'kind', 'source', 'occurred_at', 'received_at', 'estimated_at', 'time_check', 'latitude', 'longitude',
        'accuracy_m', 'site_id', 'distance_m', 'selfie_sha256', 'device_id', 'login_account_id', 'client_event_id',
        'status', 'reasons', 'signals', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime', 'received_at' => 'datetime', 'estimated_at' => 'datetime',
            'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'accuracy_m' => 'integer', 'distance_m' => 'integer',
            'reasons' => 'array', 'signals' => 'array', 'created_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function reasonTexts(): array
    {
        return array_values(array_map(fn (string $r) => self::REASONS[$r] ?? $r, $this->reasons ?? []));
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
