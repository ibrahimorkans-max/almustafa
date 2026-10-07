<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Models\Masters\MasterModel;
use App\Models\SyncConflict;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Supervisor decisions on the review queue. Either the device's data is applied
 * (re-validated, as a normal audited edit) or it is dismissed; both need a note.
 */
final class ConflictResolver
{
    public function __construct(
        private readonly SyncRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly ActorContext $actor,
    ) {}

    public function applyClient(SyncConflict $conflict, string $note): MasterModel
    {
        return DB::transaction(function () use ($conflict, $note): MasterModel {
            $conflict = $this->lockOpen($conflict);
            $model = $this->registry->masterModelFor($conflict->type) ?? throw new RuntimeException("No model for {$conflict->type}.");

            /** @var MasterModel|null $record */
            $record = $conflict->record_id ? $model::query()->withArchived()->lockForUpdate()->find($conflict->record_id) : null;
            $fields = array_intersect_key($conflict->client_payload, array_flip($model::writableFields()));
            $merged = $record === null ? $fields : array_merge($record->only($model::writableFields()), $fields);

            Validator::make($merged, $model::writeRules($record))->validate();

            if ($record === null) {
                $record = new $model;
                $record->forceFill(($conflict->record_id ? ['id' => $conflict->record_id] : []) + $fields);
            } else {
                $record->fill($fields);
                $record->archived_at = null; // applying device data deliberately restores the record
            }

            try {
                $record->save();
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('Still clashes with another record (duplicate code / number). Fix that record first or dismiss.');
            }

            $this->close($conflict, SyncConflict::REVIEW_APPLIED, $note);

            return $record;
        });
    }

    public function dismiss(SyncConflict $conflict, string $note): void
    {
        DB::transaction(fn () => $this->close($this->lockOpen($conflict), SyncConflict::REVIEW_DISMISSED, $note));
    }

    private function lockOpen(SyncConflict $conflict): SyncConflict
    {
        $fresh = SyncConflict::query()->whereKey($conflict->id)->lockForUpdate()->firstOrFail();
        if (! $fresh->isOpen()) {
            throw new RuntimeException('This item was already reviewed.');
        }

        return $fresh;
    }

    private function close(SyncConflict $conflict, string $status, string $note): void
    {
        $conflict->forceFill([
            'review_status' => $status,
            'reviewed_by' => $this->actor->accountId(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        $this->audit->record('sync.review.'.$status, $conflict, meta: ['note' => $note, 'record_id' => $conflict->record_id]);
    }
}
