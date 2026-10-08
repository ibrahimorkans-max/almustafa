<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Ops\Heartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Dispatched by the scheduler; if the worker runs it, the queue is alive. */
final class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $dispatchedAt) {}

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::QUEUE, ['dispatched_at' => $this->dispatchedAt]);
    }
}
