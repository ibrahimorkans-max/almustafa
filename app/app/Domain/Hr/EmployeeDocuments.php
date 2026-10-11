<?php

declare(strict_types=1);

namespace App\Domain\Hr;

use App\Domain\Alerts\AlertService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Alert;
use App\Models\Hr\Employee;
use App\Models\Hr\EmployeeDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Employee documents in private storage. Identity and bank documents need the sensitive permission
 * to open; every download is audited; a new copy supersedes the old one (never deleted).
 */
final class EmployeeDocuments
{
    /** Kinds that carry identity or bank numbers. */
    public const SENSITIVE_KINDS = ['cnic', 'ntn', 'bank_proof', 'id_proof'];

    public const REMINDER_DAYS = [30, 7, 0];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly FileStore $files,
        private readonly AuditLogger $audit,
        private readonly AlertService $alerts,
    ) {}

    public function upload(Employee $employee, string $kind, string $title, string $bytes, ?CarbonImmutable $validUntil = null): EmployeeDocument
    {
        $this->require('hr.employee.edit');
        if (! array_key_exists($kind, EmployeeDocument::KINDS)) {
            throw new BusinessRuleViolation('Unknown document type.');
        }
        if ($employee->status === Employee::EXITED) {
            throw new BusinessRuleViolation('An exited employee\'s file is closed.');
        }

        return DB::transaction(function () use ($employee, $kind, $title, $bytes, $validUntil): EmployeeDocument {
            $file = $this->files->put($bytes);
            $document = $employee->documents()->create([
                'kind' => $kind, 'title' => mb_substr(trim($title) ?: EmployeeDocument::KINDS[$kind], 0, 160),
                'file_id' => $file->id, 'valid_until' => $validUntil?->toDateString(), 'status' => 'active',
            ]);
            // A new copy of a one-per-employee document replaces the previous one (kept as history).
            if ($kind !== 'other') {
                $employee->documents()->where('kind', $kind)->where('status', 'active')->whereKeyNot($document->id)->get()
                    ->each(fn (EmployeeDocument $old) => $old->forceFill(['status' => 'superseded', 'superseded_by' => $document->id])->save());
            }
            if ($kind === 'photo') {
                $employee->forceFill(['photo_file_id' => $file->id])->save();
            }

            return $document;
        });
    }

    public function mayOpen(EmployeeDocument $document): bool
    {
        $account = $this->actor->account();
        if ($account === null || ! $account->hasPermission('hr.employee.view')) {
            return false;
        }

        return ! in_array($document->kind, self::SENSITIVE_KINDS, true) || $account->hasPermission('hr.employee.sensitive.view');
    }

    /** @return array{bytes: string, mime: string, name: string} */
    public function download(EmployeeDocument $document): array
    {
        if (! $this->mayOpen($document)) {
            throw new BusinessRuleViolation(in_array($document->kind, self::SENSITIVE_KINDS, true)
                ? "Identity and bank documents need 'hr.employee.sensitive.view'."
                : "You need 'hr.employee.view'.");
        }
        $file = $document->file;
        if (! $this->files->verify($file)) {
            throw new BusinessRuleViolation('The stored file does not match its recorded fingerprint; it may have been altered. Report this.');
        }
        $this->audit->record('hr.document.downloaded', $document, meta: ['employee_id' => $document->employee_id, 'kind' => $document->kind]);
        $ext = match ($file->mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', default => 'jpg'
        };

        return [
            'bytes' => (string) Storage::disk('local')->get($file->path),
            'mime' => $file->mime,
            'name' => sprintf('%s-%s.%s', $document->employee->code ?? 'employee', $document->kind, $ext),
        ];
    }

    /** Daily: alerts for active documents expiring in 30, 7 or 0 days (once per document and step). */
    public function remindExpiries(): int
    {
        $today = now()->timezone('Asia/Karachi')->startOfDay();
        $raised = 0;
        foreach (self::REMINDER_DAYS as $days) {
            $day = $today->copy()->addDays($days)->toDateString();
            EmployeeDocument::query()->with('employee.person')->where('status', 'active')->whereDate('valid_until', $day)->get()
                ->each(function (EmployeeDocument $doc) use ($days, &$raised): void {
                    $type = 'hr_document_expiry_'.$days;
                    if (Alert::query()->where('type', $type)->where('subject_id', $doc->id)->exists()) {
                        return;
                    }
                    $who = $doc->employee->name()." ({$doc->employee->code})";
                    $this->alerts->raise($type, $days === 0 ? 'critical' : 'warning',
                        $days === 0 ? "{$doc->title} of {$who} expires today" : "{$doc->title} of {$who} expires in {$days} days",
                        'Ask the employee for a renewed copy and upload it on their record.', $doc,
                        ['employee_id' => $doc->employee_id, 'valid_until' => $doc->valid_until?->toDateString()], 'hr.employee.edit');
                    $raised++;
                });
        }

        return $raised;
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}
