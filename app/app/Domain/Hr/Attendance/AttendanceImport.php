<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Punches exported from a biometric machine or the gate PC, as CSV: employee code, date-time (PKT),
 * optional direction (in / out). Previewed first; imported as punches of source "import" with the file
 * kept in the private store. A row is identified by employee and time, so importing the same file (or an
 * overlapping export) twice adds nothing. Missing directions are worked out from the order of the day's
 * punches and marked as such.
 */
final class AttendanceImport
{
    private const NAMESPACE = '6f1c0d4e-6b2a-4b1e-9f61-0d5a2c3b7e11';

    private const FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i'];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly FileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{rows: list<array{line: int, employee_id: string, code: string, at: CarbonImmutable, kind: string, inferred: bool, duplicate: bool}>, errors: list<string>}
     */
    public function preview(string $csv): array
    {
        $rows = [];
        $errors = [];
        $codes = Employee::query()->pluck('id', 'code')->all();
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            if (trim($line) === '') {
                continue;
            }
            $cols = array_map('trim', str_getcsv($line, escape: ''));
            if ($n === 1 && ! $this->parseTime($cols[1] ?? '')) {
                continue; // header row
            }
            [$code, $time, $dir] = [$cols[0] ?? '', $cols[1] ?? '', strtolower($cols[2] ?? '')];
            if (! isset($codes[$code])) {
                $errors[] = "Line {$n}: unknown employee code \"{$code}\".";

                continue;
            }
            $at = $this->parseTime($time);
            if ($at === null) {
                $errors[] = "Line {$n}: cannot read the time \"{$time}\" (use 2026-10-05 08:57 or 05/10/2026 08:57).";

                continue;
            }
            if ($at->isFuture()) {
                $errors[] = "Line {$n}: the time is in the future.";

                continue;
            }
            if (! in_array($dir, ['', 'in', 'out', 'i', 'o', '0', '1'], true)) {
                $errors[] = "Line {$n}: direction must be in, out or empty.";

                continue;
            }
            $kind = match ($dir) {
                'in', 'i', '0' => 'in', 'out', 'o', '1' => 'out', default => ''
            };
            $rows[] = ['line' => $n, 'employee_id' => $codes[$code], 'code' => $code, 'at' => $at, 'kind' => $kind, 'inferred' => false, 'duplicate' => false];
        }

        // Missing directions: in time order per employee and day, the first punch is "in", then alternating.
        usort($rows, fn ($a, $b) => [$a['employee_id'], $a['at']->getTimestamp()] <=> [$b['employee_id'], $b['at']->getTimestamp()]);
        $count = [];
        $seen = [];
        foreach ($rows as &$row) {
            $id = $this->rowId($row);
            // The same punch twice in the file, or already imported earlier.
            $row['duplicate'] = isset($seen[$id]) || AttendanceLog::query()->where('client_event_id', $id)->exists();
            $seen[$id] = true;
            if ($row['duplicate']) {
                continue;
            }
            $day = $row['employee_id'].'|'.$row['at']->toDateString();
            $count[$day] = ($count[$day] ?? 0) + 1;
            if ($row['kind'] === '') {
                $row['kind'] = $count[$day] % 2 === 1 ? 'in' : 'out';
                $row['inferred'] = true;
            }
        }
        unset($row);

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** @return array{imported: int, duplicates: int, errors: list<string>, file_sha256: string} */
    public function import(string $csv): array
    {
        if (! ($this->actor->account()?->hasPermission('hr.attendance.override') ?? false)) {
            throw new BusinessRuleViolation("Importing attendance needs 'hr.attendance.override'.");
        }
        $preview = $this->preview($csv);
        if ($preview['rows'] === []) {
            throw new BusinessRuleViolation('Nothing to import. '.implode(' ', array_slice($preview['errors'], 0, 3)));
        }

        return DB::transaction(function () use ($csv, $preview): array {
            $file = $this->files->put($csv);
            $now = now()->utc()->format('Y-m-d H:i:s.v');
            $imported = 0;
            foreach ($preview['rows'] as $row) {
                if ($row['duplicate']) {
                    continue;
                }
                AttendanceLog::query()->create([
                    'employee_id' => $row['employee_id'], 'kind' => $row['kind'], 'source' => 'import',
                    'occurred_at' => $row['at']->utc()->format('Y-m-d H:i:s.v'), 'received_at' => $now, 'time_check' => 'imported',
                    'login_account_id' => $this->actor->accountId(), 'client_event_id' => $this->rowId($row),
                    'status' => AttendanceLog::VALID, 'reasons' => $row['inferred'] ? ['imported', 'direction_inferred'] : ['imported'],
                    'signals' => ['import_file_sha256' => $file->sha256, 'line' => $row['line']], 'created_at' => $now,
                ]);
                $imported++;
            }
            $duplicates = count($preview['rows']) - $imported;
            $this->audit->record('hr.attendance.imported', null, null, null, [
                'file_sha256' => $file->sha256, 'imported' => $imported, 'duplicates' => $duplicates, 'errors' => count($preview['errors']),
            ]);

            return ['imported' => $imported, 'duplicates' => $duplicates, 'errors' => $preview['errors'], 'file_sha256' => $file->sha256];
        });
    }

    /** @param  array{employee_id: string, at: CarbonImmutable}  $row */
    private function rowId(array $row): string
    {
        return Uuid::uuid5(self::NAMESPACE, 'import|'.$row['employee_id'].'|'.$row['at']->utc()->format('Y-m-d H:i:s'))->toString();
    }

    private function parseTime(string $value): ?CarbonImmutable
    {
        foreach (self::FORMATS as $format) {
            try {
                $at = CarbonImmutable::createFromFormat($format, $value, 'Asia/Karachi');
            } catch (Throwable) {
                continue;
            }
            if ($at !== null && $at->format($format) === $value) {
                return $at;
            }
        }

        return null;
    }
}
