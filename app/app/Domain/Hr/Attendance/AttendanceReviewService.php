<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

use App\Domain\Alerts\AlertService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Alert;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\AttendanceReview;
use App\Models\Hr\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Supervisor decisions on flagged punches. The punch stays exactly as it arrived; each decision is a
 * new append-only review with its reason, and the latest one counts.
 */
final class AttendanceReviewService
{
    /** Reasons that mean "fake location", for the repeated-attempts alert. */
    public const MOCK_REASONS = ['mock_location'];

    public const INTEGRITY_REASONS = ['mock_apps_enabled', 'rooted_device', 'hooking_detected', 'emulator', 'integrity_failed', 'repeated_coordinates', 'impossible_travel', 'shared_device'];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly AlertService $alerts,
    ) {}

    public function decide(AttendanceLog $log, string $decision, string $reason): AttendanceReview
    {
        $reviewer = $this->actor->account();
        if (! ($reviewer?->hasPermission('hr.attendance.review') ?? false)) {
            throw new BusinessRuleViolation("Reviewing attendance needs 'hr.attendance.review'.");
        }
        if (! in_array($decision, [AttendanceReview::ACCEPT, AttendanceReview::REJECT], true)) {
            throw new BusinessRuleViolation('Accept or reject.');
        }
        if ($log->status !== AttendanceLog::FLAGGED) {
            throw new BusinessRuleViolation($log->status === AttendanceLog::VALID
                ? 'This punch was accepted automatically; there is nothing to review.'
                : 'This punch was rejected by a rule (for example a changed phone clock or a fake location); enter the correct time as manual attendance instead.');
        }
        if ($reviewer->person_id === $log->employee->person_id) {
            throw new BusinessRuleViolation('You cannot review your own attendance.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for the decision (what you checked).');
        }

        return DB::transaction(function () use ($log, $decision, $reason, $reviewer): AttendanceReview {
            $review = AttendanceReview::query()->create([
                'attendance_log_id' => $log->id, 'decision' => $decision, 'reason' => trim($reason),
                'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
            ]);
            $this->audit->record('hr.attendance.reviewed', $log->employee, meta: [
                'attendance_log_id' => $log->id, 'decision' => $decision, 'reason' => trim($reason), 'reasons_flagged' => $log->reasons,
            ]);

            return $review;
        });
    }

    /**
     * Repeated fake-location or tampering evidence from one employee within 7 days raises an alert once
     * per employee and week. Called after each punch is stored.
     */
    public function alertOnRepeats(Employee $employee): ?Alert
    {
        $since = now()->subDays(7)->utc()->format('Y-m-d H:i:s.v');
        $logs = AttendanceLog::query()->where('employee_id', $employee->id)->where('occurred_at', '>=', $since)->get(['reasons', 'status']);
        $mock = $logs->filter(fn (AttendanceLog $l) => array_intersect(self::MOCK_REASONS, $l->reasons ?? []) !== [])->count();
        $integrity = $logs->filter(fn (AttendanceLog $l) => array_intersect(self::INTEGRITY_REASONS, $l->reasons ?? []) !== [])->count();
        if ($mock < (int) config('ricemill.attendance.alert_mock_rejections') && $integrity < (int) config('ricemill.attendance.alert_integrity_flags')) {
            return null;
        }
        if (Alert::query()->where('type', 'attendance_tampering')->where('subject_id', $employee->id)
            ->where('created_at', '>=', now()->subDays(7))->exists()) {
            return null;
        }

        return $this->alerts->raise('attendance_tampering', 'critical',
            "Repeated fake-location / tampering signs: {$employee->name()} ({$employee->code})",
            "In the last 7 days: {$mock} punch(es) with a fake location reported by the phone, {$integrity} with other tampering signs. "
            .'Look at the punches (HR → Attendance) and talk to the employee before deciding anything.',
            $employee, ['fake_location' => $mock, 'tampering_signs' => $integrity], 'hr.attendance.review');
    }
}
