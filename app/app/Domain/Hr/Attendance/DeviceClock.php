<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

use Carbon\CarbonImmutable;

/**
 * The device's clock as reported with the current upload: an id of the boot (or app session), the
 * monotonic milliseconds since then, and the wall time. Monotonic time cannot be set by the user,
 * so a punch taken in the same boot can be dated by the server independently of the phone's clock.
 */
final class DeviceClock
{
    public ?string $bootId = null;

    public ?int $monotonicMs = null;

    public ?CarbonImmutable $wallTime = null;

    public ?CarbonImmutable $receivedAt = null;

    /** @param  array<string, mixed>|null  $clock  {boot_id, monotonic_ms, wall_time} from the push */
    public function set(?array $clock): void
    {
        $this->receivedAt = CarbonImmutable::now();
        if ($clock === null) {
            $this->bootId = null;
            $this->monotonicMs = null;
            $this->wallTime = null;

            return;
        }
        $this->bootId = isset($clock['boot_id']) ? (string) $clock['boot_id'] : null;
        $this->monotonicMs = isset($clock['monotonic_ms']) ? (int) $clock['monotonic_ms'] : null;
        $this->wallTime = isset($clock['wall_time']) ? CarbonImmutable::parse((string) $clock['wall_time'])->utc() : null;
    }

    /**
     * When the punch really happened, if it can be known: same boot, monotonic readings in order.
     */
    public function reconstruct(?string $bootId, ?int $monotonicAtPunch): ?CarbonImmutable
    {
        if ($bootId === null || $monotonicAtPunch === null || $this->bootId !== $bootId || $this->monotonicMs === null
            || $this->monotonicMs < $monotonicAtPunch) {
            return null;
        }

        return ($this->receivedAt ?? CarbonImmutable::now())->subMilliseconds($this->monotonicMs - $monotonicAtPunch);
    }
}
