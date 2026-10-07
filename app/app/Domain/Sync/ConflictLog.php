<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Masters\MasterModel;
use App\Models\SyncConflict;

/**
 * Every lost write is kept: last-write-wins decisions are logged with both sides,
 * and unmergeable events wait in the supervisor review queue.
 */
final class ConflictLog
{
    public const CLIENT_WON = 'client_won';

    public const SERVER_WON = 'server_won';

    public const NEEDS_REVIEW = 'needs_review';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function record(SyncEvent $event, string $resolution, string $reason, ?MasterModel $server, ?LoginAccount $viewer = null): SyncConflict
    {
        $conflict = SyncConflict::query()->create([
            'entity_id' => $this->tenant->requireEntityId(),
            'sync_event_id' => $event->eventId,
            'type' => strtok($event->type, '.') ?: $event->type,
            'record_id' => $event->recordId,
            'resolution' => $resolution,
            'reason' => $reason,
            'client_payload' => $event->payload,
            'server_snapshot' => $server?->attributesToArray(),
            'review_status' => $resolution === self::NEEDS_REVIEW ? SyncConflict::REVIEW_OPEN : null,
        ]);

        $this->audit->record('sync.conflict.'.$resolution, $conflict, meta: [
            'reason' => $reason, 'event_id' => $event->eventId, 'type' => $event->type, 'record_id' => $event->recordId,
        ]);

        return $conflict;
    }
}
