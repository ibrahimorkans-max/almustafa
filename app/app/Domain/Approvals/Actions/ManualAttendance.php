<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Contracts\ExplainsCheckerRule;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Audit\AuditLogger;
use App\Models\ApprovalRequest;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Manual attendance (forgotten phone, no network at a remote point, correction of a rejected
 * punch): requested with a reason by someone with hr.attendance.override, created only when a
 * different person approves. It is stored as a punch of source "manual", linked to the request.
 */
final class ManualAttendance implements ApprovalAction, ExplainsCheckerRule
{
    public const KEY = 'hr.attendance.manual';

    public function __construct(private readonly AuditLogger $audit) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'hr.attendance.override';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('hr.attendance.review');
    }

    public function whyCheckerMayNotDecide(LoginAccount $checker, ApprovalRequest $request): string
    {
        return "Manual attendance is approved by someone with 'hr.attendance.review'.";
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $data = Validator::make($payload, [
            'employee_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:in,out'],
            'at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ])->validate();

        $employee = Employee::query()->find($data['employee_id']) ?? throw ValidationException::withMessages(['employee_id' => 'Employee not found.']);
        if ($employee->status === Employee::EXITED) {
            throw ValidationException::withMessages(['employee_id' => 'The employee has exited.']);
        }
        if ($employee->person_id === $maker->person_id) {
            throw ValidationException::withMessages(['employee_id' => 'You cannot enter your own attendance.']);
        }
        $at = CarbonImmutable::parse((string) $data['at'], 'Asia/Kolkata');
        if ($at->isFuture()) {
            throw ValidationException::withMessages(['at' => 'Attendance cannot be entered for a future time.']);
        }
        if ($at->lessThan(CarbonImmutable::parse($employee->joining_date)->startOfDay())) {
            throw ValidationException::withMessages(['at' => 'This is before the employee joined.']);
        }

        return [
            'employee_id' => $employee->id, 'employee' => "{$employee->code} {$employee->name()}",
            'kind' => $data['kind'], 'at' => $at->utc()->toIso8601String(), 'at_ist' => $at->timezone('Asia/Kolkata')->format('d M Y H:i'),
            'reason' => trim($data['reason']),
        ];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        $p = $request->payload;
        $employee = Employee::query()->find($p['employee_id']) ?? throw new ApprovalDenied('Employee no longer exists.');
        $now = now();
        $log = AttendanceLog::query()->create([
            'employee_id' => $employee->id, 'kind' => $p['kind'], 'source' => 'manual',
            'occurred_at' => CarbonImmutable::parse((string) $p['at'])->utc()->format('Y-m-d H:i:s.v'),
            'received_at' => $now->utc()->format('Y-m-d H:i:s.v'), 'estimated_at' => null, 'time_check' => 'manual',
            'login_account_id' => $request->maker_login_account_id, 'client_event_id' => $request->id,
            'status' => AttendanceLog::VALID, 'reasons' => ['manual_entry'], 'approval_request_id' => $request->id,
            'created_at' => $now->utc()->format('Y-m-d H:i:s.v'),
        ]);
        $this->audit->record('hr.attendance.manual_entry', $employee, meta: [
            'attendance_log_id' => $log->id, 'kind' => $p['kind'], 'at' => $p['at'], 'reason' => $p['reason'], 'approval_request_id' => $request->id,
        ]);

        return ['attendance_log_id' => $log->id];
    }
}
