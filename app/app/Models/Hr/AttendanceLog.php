<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $ip
 * @property string|null $approval_request_id
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
        // Phone integrity (native app signals)
        'mock_location' => 'The phone reported a fake (mock / simulated) location',
        'mock_apps_enabled' => 'A fake-location app is enabled on the phone',
        'rooted_device' => 'The phone is rooted or jailbroken',
        'hooking_detected' => 'Tampering tools were detected on the phone',
        'emulator' => 'The punch came from an emulator, not a real phone',
        'integrity_failed' => 'The phone failed the Google / Apple integrity check',
        'integrity_unverified' => 'The phone\'s integrity check could not be verified',
        'integrity_unknown' => 'No phone integrity information was sent',
        // Server-side sanity checks
        'impossible_travel' => 'Too far from the previous punch for the time between them',
        'repeated_coordinates' => 'Exactly the same position as an earlier punch (real GPS always varies)',
        'suspicious_accuracy' => 'Location accuracy reported as unrealistically perfect',
        'shared_device' => 'The same phone punched for another employee shortly before',
        'device_clock_off' => 'The phone clock differs from the server clock',
        'manual_entry' => 'Entered manually (approved by a second person)',
        'imported' => 'Imported from a biometric machine / gate PC file',
        'direction_inferred' => 'In / out worked out from the order of the day\'s punches',
    ];

    protected $fillable = [
        'employee_id', 'kind', 'source', 'occurred_at', 'received_at', 'estimated_at', 'time_check', 'latitude', 'longitude',
        'accuracy_m', 'site_id', 'distance_m', 'selfie_sha256', 'device_id', 'login_account_id', 'client_event_id',
        'status', 'reasons', 'signals', 'ip', 'approval_request_id', 'created_at',
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

    /** @return HasMany<AttendanceReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(AttendanceReview::class)->orderBy('reviewed_at')->orderBy('id');
    }

    public function latestReview(): ?AttendanceReview
    {
        if ($this->relationLoaded('reviews')) {
            /** @var AttendanceReview|null */
            return $this->reviews->sortBy([['reviewed_at', 'desc'], ['id', 'desc']])->first();
        }

        return $this->reviews()->reorder()->orderByDesc('reviewed_at')->orderByDesc('id')->first();
    }

    /** What counts: the latest review decision, else the server's verdict. */
    public function finalStatus(): string
    {
        $review = $this->latestReview();

        return $review === null ? $this->status : ($review->decision === AttendanceReview::ACCEPT ? self::VALID : self::REJECTED);
    }

    /**
     * Flagged punches nobody has decided yet.
     *
     * @param  Builder<AttendanceLog>  $query
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        self::whereAwaitingReview($query);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function whereAwaitingReview(Builder $query): void
    {
        $query->where('attendance_logs.status', self::FLAGGED)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('attendance_reviews')->whereColumn('attendance_reviews.attendance_log_id', 'attendance_logs.id'));
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
