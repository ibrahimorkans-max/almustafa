<?php

declare(strict_types=1);

namespace App\Domain\Archive;

use App\Domain\Assistant\Screens;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Claims\Claim;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\Requisition;
use App\Models\Procurement\Sauda;
use App\Models\Production\ProductionBatch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Archiving closed working records after the retention period. Nothing is deleted: archiving sets
 * `archived_at` (working screens hide those rows), restoring clears it with a reason. The books,
 * stock ledger, audit log and other append-only records are the permanent record and never archived.
 */
final class ArchiveService
{
    /** type => [model, closed statuses, "closed at" expression, label] */
    public const TYPES = [
        'sauda' => [Sauda::class, ['completed', 'cancelled', 'expired'], 'COALESCE(completed_at, closed_at, updated_at)', 'Sauda'],
        'gate_entry' => [GateEntry::class, ['exited', 'cancelled'], 'COALESCE(exited_at, updated_at)', 'Gate entry'],
        'production_batch' => [ProductionBatch::class, ['closed'], 'COALESCE(closed_at, updated_at)', 'Milling batch'],
        'requisition' => [Requisition::class, ['issued', 'rejected', 'cancelled'], 'updated_at', 'Requisition'],
        'claim' => [Claim::class, ['settled', 'rejected', 'withdrawn'], 'updated_at', 'Claim'],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActorContext $actor,
    ) {}

    /**
     * Closed records whose closing is older than the cut-off and not yet archived.
     *
     * @return Builder<Model>
     */
    public function candidates(string $type, CarbonInterface $cutoff): Builder
    {
        [$model, $statuses, $closedAt] = self::TYPES[$type] ?? throw new BusinessRuleViolation("Unknown archive type {$type}.");

        /** @var Builder<Model> */
        return $model::query()->whereNull('archived_at')->whereIn('status', $statuses)
            ->whereRaw("{$closedAt} < ?", [$cutoff->toDateTimeString()]);
    }

    /**
     * @return array<string, int> archived (or, on a dry run, archivable) count per type
     */
    public function run(CarbonInterface $cutoff, bool $dryRun = false): array
    {
        $counts = [];
        foreach (array_keys(self::TYPES) as $type) {
            if ($dryRun) {
                $counts[$type] = $this->candidates($type, $cutoff)->count();

                continue;
            }
            $counts[$type] = 0;
            $this->candidates($type, $cutoff)->orderBy('id')->chunkById(200, function ($records) use (&$counts, $type): void {
                DB::transaction(function () use ($records, &$counts, $type): void {
                    foreach ($records as $record) {
                        $record->forceFill(['archived_at' => now()])->save();
                        $counts[$type]++;
                    }
                });
            });
        }
        if (! $dryRun && array_sum($counts) > 0) {
            $this->audit->record('archive.run', null, null, $counts, ['cutoff' => $cutoff->toDateString()]);
        }

        return $counts;
    }

    /**
     * Archived records matching a number, vehicle or party name.
     *
     * @return list<array{type: string, label: string, id: string, number: string, detail: string, archived_at: string}>
     */
    public function search(string $term, ?string $type = null): array
    {
        $this->require('office.view');
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            throw new BusinessRuleViolation('Type at least 2 characters.');
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)).'%';
        $rows = [];
        foreach (self::TYPES as $key => [$model, , , $label]) {
            if ($type !== null && $type !== $key) {
                continue;
            }
            $query = $model::query()->whereNotNull('archived_at')->where(function (Builder $q) use ($model, $like): void {
                $q->whereRaw('LOWER(number) LIKE ?', [$like]);
                if ($model === GateEntry::class) {
                    $q->orWhereRaw('LOWER(registration_no) LIKE ?', [$like]);
                }
                if (in_array($model, [Sauda::class, GateEntry::class, Claim::class], true)) {
                    $q->orWhereHas('party', fn (Builder $p) => $p->whereRaw('LOWER(name) LIKE ?', [$like]));
                }
            });
            foreach ($query->orderByDesc('archived_at')->limit(50)->get() as $record) {
                $rows[] = [
                    'type' => $key, 'label' => $label, 'id' => (string) $record->getKey(),
                    'number' => (string) $record->getAttribute('number'),
                    'detail' => $this->detail($record),
                    'archived_at' => (string) $record->getAttribute('archived_at'),
                ];
            }
        }

        return $rows;
    }

    /**
     * Archived records whose closing date falls in the period (Pakistan days), newest first, at most 500.
     *
     * @return list<array{type: string, label: string, id: string, number: string, detail: string, closed_at: string, archived_at: string}>
     */
    public function browse(CarbonInterface $from, CarbonInterface $to, ?string $type = null, string $term = ''): array
    {
        $this->require('office.view');
        if ($to->lessThan($from)) {
            throw new BusinessRuleViolation('The end date is before the start date.');
        }
        $start = CarbonImmutable::parse($from->toDateString(), 'Asia/Karachi')->startOfDay()->utc()->toDateTimeString();
        $end = CarbonImmutable::parse($to->toDateString(), 'Asia/Karachi')->endOfDay()->utc()->toDateTimeString();
        $like = trim($term) === '' ? null : '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($term))).'%';
        $rows = [];
        foreach (self::TYPES as $key => [$model, , $closedAt, $label]) {
            if ($type !== null && $type !== $key) {
                continue;
            }
            $query = $this->archivedIn($key, $start, $end)
                ->when($like !== null, fn (Builder $q) => $q->where(function (Builder $q) use ($model, $like): void {
                    $q->whereRaw('LOWER(number) LIKE ?', [$like]);
                    if ($model === GateEntry::class) {
                        $q->orWhereRaw('LOWER(registration_no) LIKE ?', [$like]);
                    }
                    if (in_array($model, [Sauda::class, GateEntry::class, Claim::class], true)) {
                        $q->orWhereHas('party', fn (Builder $p) => $p->whereRaw('LOWER(name) LIKE ?', [$like]));
                    }
                }))
                ->selectRaw("*, {$closedAt} as closed_on")->orderByRaw("{$closedAt} desc")->limit(500);
            foreach ($query->get() as $record) {
                $rows[] = [
                    'type' => $key, 'label' => $label, 'id' => (string) $record->getKey(),
                    'number' => (string) $record->getAttribute('number'),
                    'detail' => $this->detail($record),
                    'closed_at' => (string) $record->getAttribute('closed_on'),
                    'archived_at' => (string) $record->getAttribute('archived_at'),
                ];
            }
        }
        usort($rows, fn (array $a, array $b) => strcmp($b['closed_at'], $a['closed_at']));

        return array_slice($rows, 0, 500);
    }

    /**
     * One archived record in full, to read on the Archive screen.
     *
     * @return array{type: string, label: string, record: Model, fields: list<array{0: string, 1: string}>}
     */
    public function show(string $type, string $id): array
    {
        $this->require('office.view');
        [$model, , , $label] = self::TYPES[$type] ?? throw new BusinessRuleViolation("Unknown archive type {$type}.");
        /** @var Model|null $record */
        $record = $model::query()->whereKey($id)->whereNotNull('archived_at')->first();
        if ($record === null) {
            throw new BusinessRuleViolation('No such record here.');
        }
        $fields = [];
        foreach (['party' => 'Party', 'product' => 'Product', 'paddy' => 'Paddy'] as $relation => $name) {
            if (method_exists($record, $relation)) {
                $related = $record->getRelationValue($relation);
                if ($related instanceof Model && filled($related->getAttribute('name'))) {
                    $fields[] = [$name, (string) $related->getAttribute('name')];
                }
            }
        }

        // The screen does not name the archive: the "archived at" stamp is left out of the fields.
        $details = array_values(array_filter(Screens::details($record), fn (array $f) => ! str_starts_with(strtolower($f[0]), 'archived')));

        return ['type' => $type, 'label' => $label, 'record' => $record, 'fields' => array_merge($fields, $details)];
    }

    /**
     * Restores every archived record (of one type, or all) whose closing date falls in the period.
     *
     * @return int how many came back
     */
    public function restorePeriod(CarbonInterface $from, CarbonInterface $to, ?string $type, string $reason): int
    {
        $this->require('office.restore');
        if (mb_strlen(trim($reason)) < 10) {
            throw new BusinessRuleViolation('Restoring needs a reason (at least 10 characters).');
        }
        if ($to->lessThan($from)) {
            throw new BusinessRuleViolation('The end date is before the start date.');
        }
        $start = CarbonImmutable::parse($from->toDateString(), 'Asia/Karachi')->startOfDay()->utc()->toDateTimeString();
        $end = CarbonImmutable::parse($to->toDateString(), 'Asia/Karachi')->endOfDay()->utc()->toDateTimeString();
        $count = 0;
        foreach (array_keys(self::TYPES) as $key) {
            if ($type !== null && $type !== $key) {
                continue;
            }
            foreach ($this->archivedIn($key, $start, $end)->pluck('id') as $id) {
                $this->restore($key, (string) $id, $reason);
                $count++;
            }
        }

        return $count;
    }

    /** @return Builder<Model> archived records of a type whose closing date is in [start, end] (UTC) */
    private function archivedIn(string $type, string $start, string $end): Builder
    {
        [$model, , $closedAt] = self::TYPES[$type];

        /** @var Builder<Model> */
        return $model::query()->whereNotNull('archived_at')->whereRaw("{$closedAt} between ? and ?", [$start, $end]);
    }

    public function restore(string $type, string $id, string $reason): Model
    {
        $this->require('office.restore');
        if (mb_strlen(trim($reason)) < 10) {
            throw new BusinessRuleViolation('Restoring needs a reason (at least 10 characters).');
        }
        [$model] = self::TYPES[$type] ?? throw new BusinessRuleViolation("Unknown archive type {$type}.");

        return DB::transaction(function () use ($model, $id, $reason, $type): Model {
            /** @var Model|null $record */
            $record = $model::query()->whereKey($id)->whereNotNull('archived_at')->lockForUpdate()->first();
            if ($record === null) {
                throw new BusinessRuleViolation('No such record here.');
            }
            $record->forceFill(['archived_at' => null])->save();
            $this->audit->record('archive.restored', $record, null, null, ['type' => $type, 'reason' => $reason]);

            return $record;
        });
    }

    private function detail(Model $record): string
    {
        return match (true) {
            $record instanceof GateEntry => trim($record->registration_no.' '.($record->party->name ?? '')),
            $record instanceof Sauda, $record instanceof Claim => (string) $record->party?->name,
            default => (string) $record->getAttribute('status'),
        };
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}
