<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Hr\Leave\LeaveService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\LeaveType;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Self-service leave from the employee's phone: balances, own requests, new request, withdraw. */
final class LeaveController extends Controller
{
    public function me(Request $request, AttendanceService $attendance, LeaveService $leave): JsonResponse
    {
        $account = $this->account($request);
        $employee = $attendance->employeeFor($account);
        if ($employee === null) {
            return response()->json(['employee' => null, 'message' => 'This login is not linked to an employee record.']);
        }
        $year = (int) now('Asia/Kolkata')->year;

        return response()->json([
            'year' => $year,
            'types' => LeaveType::query()->where('status', 'active')->orderBy('code')->get()->map(fn (LeaveType $t) => [
                'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'paid' => $t->paid, 'half_day_allowed' => $t->half_day_allowed,
                'balance' => $t->paid ? $leave->balance($employee, $t, $year) : null,
            ])->all(),
            'requests' => LeaveRequest::query()->with('leaveType')->where('employee_id', $employee->id)->orderByDesc('from_date')->limit(30)->get()
                ->map(fn (LeaveRequest $r) => [
                    'id' => $r->id, 'type' => $r->leaveType->code, 'from' => $r->from_date->toDateString(), 'to' => $r->to_date->toDateString(),
                    'half_day' => $r->half_day, 'days' => (string) $r->days, 'status' => $r->status, 'note' => $r->decision_note,
                ])->all(),
        ]);
    }

    public function store(Request $request, AttendanceService $attendance, LeaveService $leave): JsonResponse
    {
        $account = $this->account($request);
        $data = $request->validate([
            'leave_type_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'half_day' => ['nullable', 'in:none,first,second'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $employee = $attendance->employeeFor($account);
        $type = LeaveType::query()->find($data['leave_type_id']);
        if ($employee === null || $type === null) {
            return response()->json(['error' => 'not_allowed', 'message' => $employee === null ? 'This login is not linked to an employee record.' : 'Unknown leave type.'], 422);
        }
        try {
            $r = $leave->request($employee, $type, CarbonImmutable::parse($data['from'], 'Asia/Kolkata'), CarbonImmutable::parse($data['to'], 'Asia/Kolkata'),
                $data['half_day'] ?? 'none', $data['reason']);
        } catch (BusinessRuleViolation $e) {
            return response()->json(['error' => 'not_allowed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $r->id, 'days' => (string) $r->days, 'status' => $r->status], 201);
    }

    public function withdraw(Request $request, string $leaveRequest, AttendanceService $attendance, LeaveService $leave): JsonResponse
    {
        $account = $this->account($request);
        $employee = $attendance->employeeFor($account);
        $r = LeaveRequest::query()->where('employee_id', $employee?->id)->find($leaveRequest);
        abort_if($r === null, 404);
        try {
            $leave->cancel($r, (string) $request->input('reason', 'Withdrawn by the employee'));
        } catch (BusinessRuleViolation $e) {
            return response()->json(['error' => 'not_allowed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $r->fresh()?->status]);
    }

    private function account(Request $request): LoginAccount
    {
        $account = $request->user();
        abort_unless($account instanceof LoginAccount && $account->hasPermission('hr.leave.request'), 403, "Leave needs 'hr.leave.request'.");

        return $account;
    }
}
