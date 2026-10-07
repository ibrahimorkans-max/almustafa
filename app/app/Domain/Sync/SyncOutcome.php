<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/** Result of one event, returned to the device and stored on sync_events. */
final readonly class SyncOutcome
{
    public const APPLIED = 'applied';

    public const CLIENT_WON = 'conflict_client_won';

    public const SERVER_WON = 'conflict_server_won';

    public const NEEDS_REVIEW = 'needs_review';

    public const PENDING_ONLINE = 'pending_online';

    public const REJECTED = 'rejected';

    /** Not applicable yet (e.g. refers to a record another device has not uploaded); nothing stored, send again later. */
    public const RETRY = 'retry';

    /**
     * @param  array<string, mixed>  $data  e.g. new version, current server record, validation errors
     */
    public function __construct(
        public string $status,
        public array $data = [],
        public ?string $reason = null,
    ) {}

    /** Statuses after which the device may drop the event from its outbox. */
    public function isFinal(): bool
    {
        return ! in_array($this->status, [self::PENDING_ONLINE, self::RETRY], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(['status' => $this->status, 'reason' => $this->reason] + $this->data, fn ($v) => $v !== null);
    }
}
