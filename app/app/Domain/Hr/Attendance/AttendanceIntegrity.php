<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

use App\Models\Hr\AttendanceLog;
use Carbon\CarbonImmutable;

/**
 * Fake-location and tampering checks for one punch. Two sources:
 *  - what the phone's operating system reports (native app `signals`), and
 *  - sanity checks the server can make itself, whatever the client.
 * Returns reason codes: `reject` when the evidence is conclusive, `flag` for a supervisor to look at.
 */
final class AttendanceIntegrity
{
    public function __construct(
        private readonly IntegrityVerifier $verifier,
        private readonly DeviceClock $clock,
    ) {}

    /**
     * Phone integrity signals: { platform: android|ios, mock_location, mock_apps_enabled, rooted, jailbroken,
     * hooking, emulator, integrity_token }.
     *
     * @param  array<string, mixed>|null  $signals
     * @return array{reject: list<string>, flag: list<string>, evidence: array<string, mixed>}
     */
    public function signals(?array $signals): array
    {
        $reject = [];
        $flag = [];
        if ($signals === null || $signals === []) {
            if (config('ricemill.attendance.require_integrity')) {
                $flag[] = 'integrity_unknown';
            }

            return ['reject' => $reject, 'flag' => $flag, 'evidence' => []];
        }
        $on = fn (string $key): bool => filter_var($signals[$key] ?? false, FILTER_VALIDATE_BOOLEAN);

        // A location the OS itself marks as simulated is conclusive.
        if ($on('mock_location')) {
            $reject[] = 'mock_location';
        }
        if ($on('mock_apps_enabled')) {
            $flag[] = 'mock_apps_enabled';
        }
        if ($on('rooted') || $on('jailbroken')) {
            $flag[] = 'rooted_device';
        }
        if ($on('hooking')) {
            $flag[] = 'hooking_detected';
        }
        if ($on('emulator')) {
            $flag[] = 'emulator';
        }

        $verdict = null;
        $token = $signals['integrity_token'] ?? null;
        if (is_string($token) && $token !== '') {
            $verdict = $this->verifier->verify((string) ($signals['platform'] ?? 'unknown'), $token);
            if ($verdict === IntegrityVerifier::FAILED) {
                $flag[] = 'integrity_failed';
            } elseif ($verdict === IntegrityVerifier::UNVERIFIED && config('ricemill.attendance.require_integrity')) {
                $flag[] = 'integrity_unverified';
            }
        } elseif (config('ricemill.attendance.require_integrity')) {
            $flag[] = 'integrity_unknown';
        }

        return ['reject' => $reject, 'flag' => $flag, 'evidence' => ['integrity_verdict' => $verdict ?? 'no_token']];
    }

    /**
     * Server-side checks against the employee's and the entity's earlier punches.
     *
     * @return list<string> flag reasons
     */
    public function sanity(string $employeeId, ?string $deviceId, ?float $lat, ?float $lng, ?int $accuracy, CarbonImmutable $at): array
    {
        $flag = [];

        if ($accuracy !== null && $accuracy < (int) config('ricemill.attendance.suspicious_accuracy_m')) {
            $flag[] = 'suspicious_accuracy';
        }

        if ($lat !== null && $lng !== null) {
            $previous = AttendanceLog::query()->where('employee_id', $employeeId)->whereNotNull('latitude')
                ->where('occurred_at', '<', $at->utc()->format('Y-m-d H:i:s.v'))->orderByDesc('occurred_at')->first();
            if ($previous !== null) {
                $hours = max($previous->occurred_at->diffInSeconds($at, true), 1) / 3600;
                $km = Geo::distanceMeters((float) $previous->latitude, (float) $previous->longitude, $lat, $lng) / 1000;
                // Ignore GPS jitter over short gaps: only distances beyond 2 km count.
                if ($km > 2 && $km / $hours > (int) config('ricemill.attendance.max_speed_kmh')) {
                    $flag[] = 'impossible_travel';
                }
            }

            $since = $at->subDays((int) config('ricemill.attendance.repeated_coordinates_days'))->utc()->format('Y-m-d H:i:s.v');
            $same = AttendanceLog::query()->where('occurred_at', '>=', $since)
                ->where('latitude', number_format($lat, 7, '.', ''))->where('longitude', number_format($lng, 7, '.', ''))->exists();
            if ($same) {
                $flag[] = 'repeated_coordinates';
            }
        }

        if ($deviceId !== null) {
            $window = (int) config('ricemill.attendance.shared_device_hours');
            $shared = AttendanceLog::query()->where('device_id', $deviceId)->where('employee_id', '!=', $employeeId)
                ->whereBetween('occurred_at', [$at->subHours($window)->utc()->format('Y-m-d H:i:s.v'), $at->addHours($window)->utc()->format('Y-m-d H:i:s.v')])
                ->exists();
            if ($shared) {
                $flag[] = 'shared_device';
            }
        }

        if ($this->clock->wallTime !== null && $this->clock->receivedAt !== null
            && abs($this->clock->wallTime->diffInSeconds($this->clock->receivedAt, false)) > (int) config('ricemill.attendance.device_clock_off_seconds')) {
            $flag[] = 'device_clock_off';
        }

        return $flag;
    }
}
