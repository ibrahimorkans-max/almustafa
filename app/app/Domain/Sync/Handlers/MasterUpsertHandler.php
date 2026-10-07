<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Sync\ConflictLog;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncEvent;
use App\Domain\Sync\SyncOutcome;
use App\Models\LoginAccount;
use App\Models\Masters\MasterModel;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

/**
 * Create-or-update of a master record from a device.
 *
 *  - unknown id                          → create with the client's UUID
 *  - base_version == current version     → apply
 *  - versions differ (concurrent edits)  → last write wins on event time; loser logged as a conflict
 *  - record archived on the server       → supervisor review
 *  - unique clash (code, reg. no, GSTIN) → supervisor review
 *  - invalid data                        → rejected with field errors
 */
final class MasterUpsertHandler implements SyncHandler
{
    /**
     * @param  class-string<MasterModel>  $model
     * @param  list<string>  $writePermissions  any of
     */
    public function __construct(
        private readonly string $model,
        private readonly array $writePermissions,
        private readonly ConflictLog $conflicts,
    ) {}

    public function type(): string
    {
        return $this->model::SYNC_TYPE.'.upsert';
    }

    public function canWrite(LoginAccount $account): bool
    {
        foreach ($this->writePermissions as $permission) {
            if ($account->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function requiresOnlineConfirmation(array $payload): bool
    {
        return false;
    }

    public function apply(SyncEvent $event, LoginAccount $account): SyncOutcome
    {
        if ($event->recordId === null) {
            return new SyncOutcome(SyncOutcome::REJECTED, reason: 'record_id_required');
        }

        $fields = array_intersect_key($event->payload, array_flip($this->model::writableFields()));

        /** @var MasterModel|null $existing */
        $existing = $this->model::query()->withArchived()->lockForUpdate()->find($event->recordId);

        if ($existing !== null && $existing->archived_at !== null) {
            $this->conflicts->record($event, ConflictLog::NEEDS_REVIEW, 'archived_on_server', $existing);

            return new SyncOutcome(SyncOutcome::NEEDS_REVIEW, reason: 'archived_on_server');
        }

        $merged = $existing === null ? $fields : array_merge($this->current($existing), $fields);
        $validator = Validator::make($merged, $this->model::writeRules($existing));
        if ($validator->fails()) {
            return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $validator->errors()->toArray()], 'validation_failed');
        }

        if ($existing === null) {
            $record = new $this->model;
            $record->forceFill(['id' => $event->recordId] + $fields);

            return $this->save($record, $event, SyncOutcome::APPLIED);
        }

        if ($event->baseVersion === $existing->version) {
            $existing->fill($fields);

            return $this->save($existing, $event, SyncOutcome::APPLIED);
        }

        // Concurrent edit. Last write wins on event time (clamped to receipt time by SyncService).
        if ($event->occurredAt->greaterThanOrEqualTo($existing->updated_at)) {
            $this->conflicts->record($event, ConflictLog::CLIENT_WON, 'version_mismatch', $existing);
            $existing->fill($fields);

            return $this->save($existing, $event, SyncOutcome::CLIENT_WON);
        }

        $this->conflicts->record($event, ConflictLog::SERVER_WON, 'version_mismatch', $existing);

        return new SyncOutcome(SyncOutcome::SERVER_WON, [
            'version' => $existing->version,
            'record' => $existing->toSyncArray($account),
        ], 'version_mismatch');
    }

    private function save(MasterModel $record, SyncEvent $event, string $status): SyncOutcome
    {
        try {
            $record->save(); // savepoint: a failure here does not poison the batch transaction
        } catch (UniqueConstraintViolationException) {
            $this->conflicts->record($event, ConflictLog::NEEDS_REVIEW, 'duplicate_value', null);

            return new SyncOutcome(SyncOutcome::NEEDS_REVIEW, reason: 'duplicate_value');
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'row-level security')) {
                return new SyncOutcome(SyncOutcome::REJECTED, reason: 'record_id_in_use');
            }
            throw $e;
        }

        return new SyncOutcome($status, ['version' => (int) $record->version]);
    }

    /** @return array<string, mixed> */
    private function current(MasterModel $model): array
    {
        $values = [];
        foreach ($this->model::writableFields() as $field) {
            $value = $model->getAttribute($field);
            $values[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $values;
    }
}
