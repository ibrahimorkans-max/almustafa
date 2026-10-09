<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\EmployeeDevice;
use App\Models\Hr\Site;
use App\Models\LoginAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service attendance for the employee's own phone. Punches themselves travel through the sync
 * push (`attendance.punch`) so that offline punches use the same queue and idempotency.
 */
final class AttendanceController extends Controller
{
    public function me(Request $request, AttendanceService $attendance): JsonResponse
    {
        $account = $this->account($request);
        $employee = $attendance->employeeFor($account);
        if ($employee === null) {
            return response()->json(['employee' => null, 'message' => AttendanceLog::REASONS['no_employee'].'.']);
        }
        $device = $this->device($request);
        $binding = EmployeeDevice::query()->where('employee_id', $employee->id)->where('device_id', $device->id)->first();
        $today = now()->timezone('Asia/Kolkata')->startOfDay()->utc();

        return response()->json([
            'employee' => ['id' => $employee->id, 'code' => $employee->code, 'name' => $employee->name(), 'status' => $employee->status],
            'notice' => [
                'version' => (string) config('ricemill.attendance.notice_version'),
                'text' => (string) config('ricemill.attendance.notice'),
                'accepted' => $attendance->hasConsent($employee),
            ],
            'device' => ['status' => $binding->status ?? 'not_requested'],
            'require_selfie' => (bool) config('ricemill.attendance.require_selfie'),
            'sites' => Site::query()->where('status', 'active')->orderBy('name')->get()
                ->map(fn (Site $s) => ['code' => $s->code, 'name' => $s->name, 'lat' => (float) $s->latitude, 'lng' => (float) $s->longitude, 'radius_m' => $s->radius_m])->all(),
            'today' => AttendanceLog::query()->where('employee_id', $employee->id)->where('occurred_at', '>=', $today)
                ->orderBy('occurred_at')->get()
                ->map(fn (AttendanceLog $l) => [
                    'kind' => $l->kind, 'at' => $l->occurred_at->toIso8601String(), 'status' => $l->status, 'messages' => $l->reasonTexts(),
                ])->all(),
        ]);
    }

    public function consent(Request $request, AttendanceService $attendance): JsonResponse
    {
        $data = $request->validate(['notice_version' => ['required', 'string', 'max:20']]);
        try {
            $consent = $attendance->consent($this->account($request), $this->device($request), $request->ip(), $data['notice_version']);
        } catch (BusinessRuleViolation $e) {
            return response()->json(['error' => 'not_allowed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['accepted_at' => $consent->accepted_at->toIso8601String()], 201);
    }

    public function requestDevice(Request $request, AttendanceService $attendance): JsonResponse
    {
        try {
            $binding = $attendance->requestDevice($this->account($request), $this->device($request));
        } catch (BusinessRuleViolation $e) {
            return response()->json(['error' => 'not_allowed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $binding->status], $binding->wasRecentlyCreated ? 201 : 200);
    }

    private function account(Request $request): LoginAccount
    {
        $account = $request->user();
        abort_unless($account instanceof LoginAccount && $account->hasPermission('hr.attendance.punch'), 403, "Attendance needs 'hr.attendance.punch'.");

        return $account;
    }

    private function device(Request $request): Device
    {
        /** @var Device */
        return $request->attributes->get('auth_device');
    }
}
