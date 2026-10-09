<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Auth\ActorContext;
use App\Domain\Files\FileStore;
use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Sync\Contracts\AcceptsLateEvents;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncEvent;
use App\Domain\Sync\SyncOutcome;
use App\Models\Device;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * `attendance.punch` from a phone (online or from its offline queue). The punch is stored with the
 * server's verdict; the device is told the verdict and the reasons. A selfie that has not arrived
 * yet makes the event wait (retry) instead of losing the evidence.
 */
final class AttendanceHandler implements AcceptsLateEvents, SyncHandler
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly FileStore $files,
        private readonly ActorContext $actor,
    ) {}

    public function type(): string
    {
        return 'attendance.punch';
    }

    public function canWrite(LoginAccount $account): bool
    {
        return $account->hasPermission('hr.attendance.punch');
    }

    public function requiresOnlineConfirmation(array $payload): bool
    {
        return false;
    }

    public function apply(SyncEvent $event, LoginAccount $account): SyncOutcome
    {
        $v = Validator::make($event->payload, [
            'kind' => ['required', 'in:in,out'],
            'device_time' => ['required', 'date'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'selfie_sha256' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
            'boot_id' => ['nullable', 'string', 'max:64'],
            'monotonic_ms' => ['nullable', 'integer', 'min:0'],
            'source' => ['nullable', 'in:pwa,app'],
            'signals' => ['nullable', 'array'],
        ]);
        if ($v->fails()) {
            return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
        }
        $sha = $event->payload['selfie_sha256'] ?? null;
        if ($sha !== null && ! $this->files->exists((string) $sha)) {
            return new SyncOutcome(SyncOutcome::RETRY, reason: 'selfie_not_uploaded');
        }

        try {
            $deviceId = $this->actor->deviceId();
            $log = $this->attendance->punch(
                $account,
                $deviceId !== null ? Device::query()->find($deviceId) : null,
                $event->eventId,
                // The punch's own device time (the generic event time is clamped for future skew).
                CarbonImmutable::parse((string) $event->payload['device_time'])->utc(),
                $event->payload,
                (string) ($event->payload['source'] ?? 'app'),
            );
        } catch (BusinessRuleViolation $e) {
            return new SyncOutcome(SyncOutcome::REJECTED, ['message' => $e->getMessage()], 'business_rule');
        }

        // The punch is recorded either way; the verdict travels back to the phone in plain words.
        return new SyncOutcome(SyncOutcome::APPLIED, [
            'attendance_id' => $log->id, 'attendance_status' => $log->status,
            'reasons' => $log->reasons, 'messages' => $log->reasonTexts(),
        ]);
    }
}
