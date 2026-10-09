<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Sync\Contracts\AcceptsLateEvents;
use App\Domain\Tenancy\TenantContext;
use App\Models\Device;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Push (device → server) and pull (server → device) for every client: PWA, Flutter, gate agent.
 */
final class SyncService
{
    public function __construct(
        private readonly SyncRegistry $registry,
        private readonly SyncFeed $feed,
        private readonly ConflictLog $conflicts,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Processes events in client_seq order, each in its own transaction so one bad event
     * cannot block the queue. Re-sent events return their stored result (idempotent).
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function push(LoginAccount $account, Device $device, array $events): array
    {
        usort($events, fn (array $a, array $b) => (int) $a['client_seq'] <=> (int) $b['client_seq']);

        return array_map(fn (array $raw) => $this->pushOne($account, $device, SyncEvent::fromArray($raw)), $events);
    }

    /** @return array<string, mixed> */
    private function pushOne(LoginAccount $account, Device $device, SyncEvent $event): array
    {
        $base = ['event_id' => $event->eventId, 'client_seq' => $event->clientSeq];

        try {
            return $this->pushOneInTransaction($account, $device, $event, $base);
        } catch (RetryLater $retry) {
            return $base + $retry->outcome->toArray();
        } catch (\Throwable $e) {
            // Nothing was stored: the device keeps the event and retries later.
            report($e);

            return $base + ['status' => 'retry', 'reason' => 'server_error'];
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function pushOneInTransaction(LoginAccount $account, Device $device, SyncEvent $event, array $base): array
    {
        return DB::transaction(function () use ($account, $device, $event, $base): array {
            // Idempotency: the same event_id always gets the same answer.
            $stored = DB::table('sync_events')->where('id', $event->eventId)->first();
            if ($stored !== null) {
                if ($stored->device_id !== $device->id) {
                    return $base + ['status' => SyncOutcome::REJECTED, 'reason' => 'event_id_reused'];
                }

                return $base + (array) json_decode((string) $stored->result, true) + ['duplicate' => true];
            }

            // Serialise pushes from the same device (two tabs / retries racing).
            Device::query()->whereKey($device->id)->lockForUpdate()->first();
            // client_seq orders a batch and must be unique per device. It need not be higher than
            // everything stored: an event answered "retry" is re-sent later with its original seq.
            if (DB::table('sync_events')->where('device_id', $device->id)->where('client_seq', $event->clientSeq)->exists()) {
                return $base + ['status' => SyncOutcome::REJECTED, 'reason' => 'client_seq_reused'];
            }

            $receivedAt = CarbonImmutable::now('UTC');
            $event = $this->clampClock($event, $receivedAt);
            $outcome = $this->decide($account, $event, $receivedAt);

            if ($outcome->status === SyncOutcome::RETRY) {
                // Nothing is stored (the handler's work is rolled back); the device sends it again later.
                throw new RetryLater($outcome);
            }

            DB::table('sync_events')->insert([
                'id' => $event->eventId,
                'entity_id' => $this->tenant->requireEntityId(),
                'device_id' => $device->id,
                'login_account_id' => $account->id,
                'client_seq' => $event->clientSeq,
                'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s.v'),
                'received_at' => $receivedAt->format('Y-m-d H:i:s.v'),
                'type' => $event->type,
                'record_id' => $event->recordId,
                'base_version' => $event->baseVersion,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'status' => $outcome->status,
                'result' => json_encode($outcome->toArray(), JSON_THROW_ON_ERROR),
                'created_at' => $receivedAt,
            ]);

            return $base + $outcome->toArray();
        });
    }

    private function decide(LoginAccount $account, SyncEvent $event, CarbonImmutable $receivedAt): SyncOutcome
    {
        $handler = $this->registry->handler($event->type);
        if ($handler === null) {
            return new SyncOutcome(SyncOutcome::REJECTED, reason: 'unknown_event_type');
        }
        if (! $handler->canWrite($account)) {
            return new SyncOutcome(SyncOutcome::REJECTED, reason: 'forbidden');
        }

        $maxOfflineHours = (int) config('ricemill.sync.max_offline_hours');
        if (! $handler instanceof AcceptsLateEvents && $event->occurredAt->lt($receivedAt->subHours($maxOfflineHours))) {
            $this->conflicts->record($event, ConflictLog::NEEDS_REVIEW, 'older_than_offline_limit', null);

            return new SyncOutcome(SyncOutcome::NEEDS_REVIEW, reason: 'older_than_offline_limit');
        }

        if ($handler->requiresOnlineConfirmation($event->payload)) {
            return new SyncOutcome(SyncOutcome::PENDING_ONLINE, reason: 'online_confirmation_required');
        }

        return $handler->apply($event, $account);
    }

    /** A device clock running ahead must not win every last-write-wins decision. */
    private function clampClock(SyncEvent $event, CarbonImmutable $receivedAt): SyncEvent
    {
        $limit = $receivedAt->addSeconds((int) config('ricemill.sync.max_future_skew_seconds'));
        if ($event->occurredAt->lte($limit)) {
            return $event;
        }

        return new SyncEvent($event->eventId, $event->clientSeq, $receivedAt, $event->type, $event->recordId, $event->baseVersion, $event->payload);
    }

    /**
     * Changes after $cursor, deduplicated to the latest state per record and serialised for
     * the viewer (permission-filtered fields). Archived records come back as op "archive"
     * so devices drop them from local storage.
     *
     * @return array{changes: list<array<string, mixed>>, next_cursor: int, has_more: bool}
     */
    public function pull(LoginAccount $viewer, int $cursor, int $limit): array
    {
        $entityId = $this->tenant->requireEntityId();
        $rows = DB::table('sync_changes')
            ->where('entity_id', $entityId)
            ->where('seq', '>', $cursor)
            ->orderBy('seq')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $nextCursor = $rows->isEmpty() ? $cursor : (int) $rows->last()->seq;

        // Keep the last change per record, in feed order.
        $latest = [];
        foreach ($rows as $row) {
            if ($this->registry->canRead($viewer, $row->type)) {
                unset($latest[$row->type.':'.$row->record_id]);
                $latest[$row->type.':'.$row->record_id] = $row;
            }
        }

        $byType = [];
        foreach ($latest as $row) {
            $byType[$row->type][] = $row->record_id;
        }
        $records = [];
        foreach ($byType as $type => $ids) {
            $model = $this->registry->modelFor($type);
            $records[$type] = $model === null ? collect() : $model::query()->withoutGlobalScope('not_archived')->whereIn('id', $ids)->get()->keyBy('id');
        }

        $changes = [];
        foreach ($latest as $row) {
            $record = $records[$row->type][$row->record_id] ?? null;
            if ($record === null) {
                continue;
            }
            $changes[] = $record->archived_at !== null
                ? ['seq' => (int) $row->seq, 'type' => $row->type, 'op' => 'archive', 'id' => $record->id, 'version' => (int) $record->version]
                : ['seq' => (int) $row->seq, 'type' => $row->type, 'op' => 'upsert', 'id' => $record->id, 'version' => (int) $record->version, 'data' => $record->toSyncArray($viewer)];
        }

        return ['changes' => $changes, 'next_cursor' => $nextCursor, 'has_more' => $hasMore];
    }

    public function cursor(): int
    {
        return $this->feed->head($this->tenant->requireEntityId());
    }
}
