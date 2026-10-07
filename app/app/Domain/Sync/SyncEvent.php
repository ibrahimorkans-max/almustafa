<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use Carbon\CarbonImmutable;

/** One client event as received in a push batch. */
final readonly class SyncEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public int $clientSeq,
        public CarbonImmutable $occurredAt,
        public string $type,
        public ?string $recordId,
        public ?int $baseVersion,
        public array $payload,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['event_id'],
            (int) $data['client_seq'],
            CarbonImmutable::parse((string) $data['occurred_at'])->utc(),
            (string) $data['type'],
            isset($data['record_id']) ? (string) $data['record_id'] : null,
            isset($data['base_version']) ? (int) $data['base_version'] : null,
            (array) ($data['payload'] ?? []),
        );
    }
}
