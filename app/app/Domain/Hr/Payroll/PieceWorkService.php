<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Hr\Employee;
use App\Models\Hr\PieceWorkEntry;
use App\Models\Production\ProductionBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records piece work (bags loaded / unloaded, maund milled …) for piece-rate wages. Work done by a
 * gang is split equally (to the gram / thousandth unit; the remainder goes to the first person, so
 * the parts always add up). A gate entry or production batch is counted once per employee and
 * unit; mistakes are corrected by a negative entry, never by editing.
 */
final class PieceWorkService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $employeeIds
     * @return list<PieceWorkEntry>
     */
    public function record(array $employeeIds, CarbonImmutable $date, string $unit, string $totalQuantity, string $sourceType = 'manual', ?string $sourceId = null, ?string $note = null): array
    {
        $this->require();
        $employeeIds = array_values(array_unique($employeeIds));
        if ($employeeIds === []) {
            throw new BusinessRuleViolation('Choose who did the work.');
        }
        if (! array_key_exists($unit, PieceWorkEntry::UNITS)) {
            throw new BusinessRuleViolation('Unknown unit of work.');
        }
        if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', $totalQuantity) || bccomp($totalQuantity, '0', 3) <= 0) {
            throw new BusinessRuleViolation('Enter a positive quantity (up to 3 decimals).');
        }
        if ($date->greaterThan(CarbonImmutable::now('Asia/Karachi')->endOfDay())) {
            throw new BusinessRuleViolation('Work cannot be recorded for a future date.');
        }
        $employees = Employee::query()->whereKey($employeeIds)->get()->keyBy('id');
        if ($employees->count() !== count($employeeIds)) {
            throw new BusinessRuleViolation('Unknown employee.');
        }
        foreach ($employees as $e) {
            if ($date->toDateString() < $e->joining_date->toDateString() || ($e->exited_on !== null && $date->toDateString() > $e->exited_on->toDateString())) {
                throw new BusinessRuleViolation("{$e->code} was not employed on {$date->format('d M Y')}.");
            }
        }

        if ($sourceId !== null && PieceWorkEntry::query()->whereIn('employee_id', $employeeIds)->where('source_key', "{$sourceType}:{$sourceId}:{$unit}")->exists()) {
            throw new BusinessRuleViolation('This work has already been recorded for one of these employees.');
        }

        // Equal shares in thousandths; the remainder goes to the first person.
        $n = count($employeeIds);
        $milli = (int) bcmul($totalQuantity, '1000', 0);
        $share = intdiv($milli, $n);
        $first = $milli - $share * ($n - 1);

        try {
            return DB::transaction(function () use ($employeeIds, $date, $unit, $sourceType, $sourceId, $note, $share, $first, $totalQuantity, $n): array {
                $entries = [];
                foreach ($employeeIds as $i => $id) {
                    $entries[] = PieceWorkEntry::query()->create([
                        'employee_id' => $id, 'work_date' => $date->toDateString(), 'unit' => $unit,
                        'quantity' => bcdiv((string) ($i === 0 ? $first : $share), '1000', 3),
                        'source_type' => $sourceType, 'source_id' => $sourceId,
                        'source_key' => $sourceId !== null ? "{$sourceType}:{$sourceId}:{$unit}" : null,
                        'note' => $note, 'created_at' => now(),
                    ]);
                }
                $this->audit->record('hr.piece_work.recorded', null, null, null, [
                    'date' => $date->toDateString(), 'unit' => $unit, 'quantity' => $totalQuantity, 'people' => $n,
                    'source_type' => $sourceType, 'source_id' => $sourceId,
                ]);

                return $entries;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('This work has already been recorded for one of these employees.');
        }
    }

    /**
     * Bags loaded or unloaded through a gate entry, split across the loading gang.
     *
     * @param  list<string>  $employeeIds
     * @return list<PieceWorkEntry>
     */
    public function fromGateEntry(GateEntry $entry, array $employeeIds): array
    {
        if ((int) $entry->bag_count <= 0) {
            throw new BusinessRuleViolation('The gate entry has no bag count to pay for.');
        }
        $date = CarbonImmutable::instance($entry->exited_at ?? $entry->arrived_at)->setTimezone('Asia/Karachi')->startOfDay();

        return $this->record($employeeIds, $date, 'bag', (string) $entry->bag_count, 'gate_entry', $entry->id,
            ($entry->direction === 'inbound' ? 'Unloaded ' : 'Loaded ').$entry->number);
    }

    /**
     * Paddy milled in a production batch (input weight in maund), split across the mill crew.
     *
     * @param  list<string>  $employeeIds
     * @return list<PieceWorkEntry>
     */
    public function fromBatch(ProductionBatch $batch, array $employeeIds): array
    {
        $maund = bcdiv((string) $batch->input_kg, '40', 3);
        if (bccomp($maund, '0', 3) <= 0) {
            throw new BusinessRuleViolation('The batch has no paddy input yet.');
        }
        $date = CarbonImmutable::instance($batch->closed_at ?? $batch->opened_at)->setTimezone('Asia/Karachi')->startOfDay();

        return $this->record($employeeIds, $date, 'maund', $maund, 'production_batch', $batch->id, 'Milled in batch '.$batch->number);
    }

    /** Offsets a wrong entry with a negative one (the original stays visible). */
    public function correct(PieceWorkEntry $entry, string $reason): PieceWorkEntry
    {
        $this->require();
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for the correction.');
        }
        if ($entry->source_type === 'correction' || bccomp((string) $entry->quantity, '0', 3) <= 0) {
            throw new BusinessRuleViolation('A correction cannot itself be corrected; record the right quantity instead.');
        }
        if (PieceWorkEntry::query()->where('employee_id', $entry->employee_id)->where('source_key', "correction:{$entry->id}")->exists()) {
            throw new BusinessRuleViolation('This entry has already been corrected.');
        }
        try {
            $correction = DB::transaction(fn () => PieceWorkEntry::query()->create([
                'employee_id' => $entry->employee_id, 'work_date' => $entry->work_date->toDateString(), 'unit' => $entry->unit,
                'quantity' => bcmul((string) $entry->quantity, '-1', 3), 'source_type' => 'correction', 'source_id' => $entry->id,
                'source_key' => "correction:{$entry->id}", 'note' => trim($reason), 'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleViolation('This entry has already been corrected.');
        }
        $this->audit->record('hr.piece_work.corrected', $correction, null, null, ['original' => $entry->id, 'reason' => trim($reason)]);

        return $correction;
    }

    private function require(): void
    {
        $account = $this->actor->account();
        if (! ($account?->hasPermission('hr.payroll.run') || $account?->hasPermission('hr.attendance.review'))) {
            throw new BusinessRuleViolation("Recording piece work needs 'hr.payroll.run' or 'hr.attendance.review'.");
        }
    }
}
