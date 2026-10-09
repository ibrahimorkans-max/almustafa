<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Device;
use App\Models\Hr\AttendanceConsent;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeDevice;
use App\Models\Hr\Site;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance from the employee's own phone: consent to the notice, device binding and punches.
 * Every punch is stored as it arrived, with a verdict — valid, flagged (for a supervisor) or
 * rejected — and the reasons in plain words. Nothing is silently accepted or silently dropped.
 */
final class AttendanceService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DeviceClock $clock,
        private readonly AttendanceIntegrity $integrity,
    ) {}

    /** The active employee record of a login (same person, same entity), if any. */
    public function employeeFor(LoginAccount $account): ?Employee
    {
        return Employee::query()->where('person_id', $account->person_id)
            ->where('status', '!=', Employee::EXITED)->orderBy('created_at')->first();
    }

    public function hasConsent(Employee $employee): bool
    {
        return AttendanceConsent::query()->where('employee_id', $employee->id)
            ->where('notice_version', (string) config('ricemill.attendance.notice_version'))->exists();
    }

    public function consent(LoginAccount $account, ?Device $device, ?string $ip, string $noticeVersion): AttendanceConsent
    {
        $employee = $this->employeeFor($account) ?? throw new BusinessRuleViolation(AttendanceLog::REASONS['no_employee'].'.');
        if ($noticeVersion !== (string) config('ricemill.attendance.notice_version')) {
            throw new BusinessRuleViolation('The notice has changed. Read the current notice and accept it again.');
        }

        return AttendanceConsent::query()->create([
            'employee_id' => $employee->id, 'login_account_id' => $account->id, 'device_id' => $device?->id,
            'notice_version' => $noticeVersion, 'accepted_at' => now(), 'ip' => $ip,
        ]);
    }

    /** The phone asks to be the employee's attendance device; HR decides. */
    public function requestDevice(LoginAccount $account, Device $device): EmployeeDevice
    {
        $employee = $this->employeeFor($account) ?? throw new BusinessRuleViolation(AttendanceLog::REASONS['no_employee'].'.');
        $existing = EmployeeDevice::query()->where('employee_id', $employee->id)->where('device_id', $device->id)->first();
        if ($existing !== null && in_array($existing->status, [EmployeeDevice::PENDING, EmployeeDevice::APPROVED], true)) {
            return $existing;
        }

        return DB::transaction(function () use ($employee, $device, $existing): EmployeeDevice {
            $binding = $existing ?? new EmployeeDevice(['employee_id' => $employee->id, 'device_id' => $device->id]);
            $binding->forceFill(['status' => EmployeeDevice::PENDING, 'requested_at' => now(), 'decided_by' => null, 'decided_at' => null])->save();

            return $binding;
        });
    }

    /** HR approves (retiring any previous phone) or rejects a device request. */
    public function decideDevice(EmployeeDevice $binding, bool $approve, ?string $note): EmployeeDevice
    {
        $actor = $this->actor->account();
        if (! ($actor?->hasPermission('hr.employee.edit') ?? false)) {
            throw new BusinessRuleViolation("Approving attendance devices needs 'hr.employee.edit'.");
        }
        if ($actor->person_id === $binding->employee->person_id) {
            throw new BusinessRuleViolation('You cannot approve your own attendance device.');
        }
        if ($binding->status !== EmployeeDevice::PENDING) {
            throw new BusinessRuleViolation('This request has already been decided.');
        }
        if (! $approve && mb_strlen(trim((string) $note)) < 3) {
            throw new BusinessRuleViolation('Give the reason for rejecting the device.');
        }

        return DB::transaction(function () use ($binding, $approve, $note, $actor): EmployeeDevice {
            if ($approve) {
                EmployeeDevice::query()->where('employee_id', $binding->employee_id)->where('status', EmployeeDevice::APPROVED)
                    ->whereKeyNot($binding->id)->get()
                    ->each(fn (EmployeeDevice $old) => $old->forceFill(['status' => EmployeeDevice::REVOKED, 'decided_by' => $actor->id,
                        'decided_at' => now(), 'decision_note' => 'Replaced by a newly approved device'])->save());
            }
            $binding->forceFill([
                'status' => $approve ? EmployeeDevice::APPROVED : EmployeeDevice::REJECTED,
                'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note,
            ])->save();
            $this->audit->record('hr.attendance.device_'.($approve ? 'approved' : 'rejected'), $binding->employee, meta: [
                'device_id' => $binding->device_id, 'device' => $binding->device->name ?? null, 'note' => $note,
            ]);

            return $binding;
        });
    }

    /**
     * Records one punch (online or from the offline queue). Idempotent on the client event id.
     *
     * @param  array<string, mixed>  $p  kind, lat, lng, accuracy_m, selfie_sha256, boot_id, monotonic_ms, signals
     */
    public function punch(LoginAccount $account, ?Device $device, string $eventId, CarbonImmutable $occurredAt, array $p, string $source): AttendanceLog
    {
        $existing = AttendanceLog::query()->where('client_event_id', $eventId)->first();
        if ($existing !== null) {
            return $existing;
        }
        $kind = $p['kind'] ?? null;
        if (! in_array($kind, ['in', 'out'], true)) {
            throw new BusinessRuleViolation('A punch is "in" or "out".');
        }
        $employee = $this->employeeFor($account);
        if ($employee === null) {
            // Nowhere to file it: the login has no employee record in this entity.
            throw new BusinessRuleViolation(AttendanceLog::REASONS['no_employee'].'.');
        }

        $now = CarbonImmutable::now();
        $reject = [];
        $flag = [];

        if ($employee->status !== Employee::ACTIVE && $employee->status !== Employee::ON_NOTICE) {
            $reject[] = 'employee_not_active';
        }
        if (! $this->hasConsent($employee)) {
            $reject[] = 'no_consent';
        }

        // Time: reconstruct from the monotonic clock when possible.
        $estimated = $this->clock->reconstruct(isset($p['boot_id']) ? (string) $p['boot_id'] : null,
            isset($p['monotonic_ms']) ? (int) $p['monotonic_ms'] : null);
        $timeCheck = 'unverified';
        if ($estimated !== null) {
            $timeCheck = abs($estimated->diffInSeconds($occurredAt, false)) > (int) config('ricemill.attendance.clock_tolerance_seconds')
                ? 'changed' : 'verified';
        }
        if ($timeCheck === 'changed') {
            $reject[] = 'device_clock_changed';
        } elseif ($timeCheck === 'unverified') {
            $flag[] = 'time_unverified';
        }
        if ($occurredAt->greaterThan($now->addSeconds((int) config('ricemill.sync.max_future_skew_seconds')))) {
            $reject[] = 'future_time';
        }
        if ($occurredAt->lessThan($now->subHours((int) config('ricemill.attendance.late_upload_hours')))) {
            $flag[] = 'late_upload';
        }

        // Device binding.
        $approved = $device !== null && EmployeeDevice::query()->where('employee_id', $employee->id)
            ->where('device_id', $device->id)->where('status', EmployeeDevice::APPROVED)->exists();
        if (! $approved) {
            $flag[] = 'device_not_approved';
        }

        // Place and hours.
        [$lat, $lng, $accuracy] = [self::num($p['lat'] ?? null), self::num($p['lng'] ?? null), isset($p['accuracy_m']) ? (int) round((float) $p['accuracy_m']) : null];
        $site = null;
        $distance = null;
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            $flag[] = 'no_location';
            $lat = $lng = null;
        } else {
            if ($accuracy === null || $accuracy > (int) config('ricemill.attendance.max_accuracy_m')) {
                $flag[] = 'low_accuracy';
            }
            [$site, $distance] = $this->nearestSite($lat, $lng);
            $inside = $site !== null && $distance <= $site->radius_m + min($accuracy ?? 0, (int) config('ricemill.attendance.max_accuracy_m'));
            if (! $inside) {
                $flag[] = 'outside_geofence';
            } elseif (! $site->isOpenAt($estimated ?? $occurredAt)) {
                $flag[] = 'outside_time_window';
            }
        }

        $selfie = isset($p['selfie_sha256']) && preg_match('/^[0-9a-f]{64}$/', (string) $p['selfie_sha256']) ? (string) $p['selfie_sha256'] : null;
        if ($selfie === null && config('ricemill.attendance.require_selfie')) {
            $flag[] = 'no_selfie';
        }

        // Fake-location / tampering evidence from the phone, then the server's own sanity checks.
        $signals = is_array($p['signals'] ?? null) ? $p['signals'] : null;
        $integrity = $this->integrity->signals($signals);
        array_push($reject, ...$integrity['reject']);
        array_push($flag, ...$integrity['flag'], ...$this->integrity->sanity($employee->id, $device?->id, $lat, $lng, $accuracy, $estimated ?? $occurredAt));

        $last = AttendanceLog::query()->where('employee_id', $employee->id)->where('status', '!=', AttendanceLog::REJECTED)
            ->where('occurred_at', '<', $occurredAt->utc()->format('Y-m-d H:i:s.v'))->orderByDesc('occurred_at')->first();
        if ($last !== null && $last->kind === $kind && $last->occurred_at->isSameDay($occurredAt)) {
            $flag[] = 'sequence';
        }

        $status = $reject !== [] ? AttendanceLog::REJECTED : ($flag !== [] ? AttendanceLog::FLAGGED : AttendanceLog::VALID);

        return AttendanceLog::query()->create([
            'employee_id' => $employee->id, 'kind' => $kind, 'source' => $source,
            'occurred_at' => $occurredAt->utc()->format('Y-m-d H:i:s.v'), 'received_at' => $now->utc()->format('Y-m-d H:i:s.v'),
            'estimated_at' => $estimated?->utc()->format('Y-m-d H:i:s.v'), 'time_check' => $timeCheck,
            'latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => $accuracy,
            'site_id' => $site?->id, 'distance_m' => $distance, 'selfie_sha256' => $selfie,
            'device_id' => $device?->id, 'login_account_id' => $account->id, 'client_event_id' => $eventId,
            'status' => $status, 'reasons' => array_values(array_unique([...$reject, ...$flag])),
            'signals' => $signals !== null ? $signals + $integrity['evidence'] : null,
            'ip' => $this->actor->ip(),
            'created_at' => $now->utc()->format('Y-m-d H:i:s.v'),
        ]);
    }

    /** @return array{0: Site|null, 1: int|null} */
    private function nearestSite(float $lat, float $lng): array
    {
        $best = null;
        $bestDistance = null;
        foreach (Site::query()->where('status', 'active')->get() as $site) {
            $d = Geo::distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude);
            if ($bestDistance === null || $d < $bestDistance) {
                [$best, $bestDistance] = [$site, $d];
            }
        }

        return [$best, $bestDistance];
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
