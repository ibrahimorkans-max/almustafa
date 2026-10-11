<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\CanonicalJson;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Files\FileStore;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Documents\DocumentAccessLog;
use App\Models\Documents\DocumentShare;
use App\Models\Documents\DocumentTemplate;
use App\Models\Documents\GeneratedDocument;
use App\Models\Entity;
use App\Models\Hr\Employee;
use App\Support\Values\PakistanIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one way documents are made. Records that are not final yet (a calculated payroll) can only be
 * previewed, watermarked DRAFT. A final record is issued once: numbered (its own number or a
 * gap-free series), its content frozen, the original PDF stored with its SHA-256, and a verification
 * code printed with a QR code. Afterwards the stored original can be downloaded as it is, or
 * reprinted in any layout, language and paper from the same frozen content, watermarked DUPLICATE
 * and counted. A correction is a new revision of the same number pointing at the original.
 * Every issue, download, reprint and share is in the access log.
 */
final class DocumentService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly FileStore $files,
        private readonly TemplateRepository $templates,
        private readonly DocumentRenderer $renderer,
    ) {}

    /**
     * A draft (not final) or a sample (final, not yet issued) in any options, watermarked DRAFT. Nothing is stored.
     *
     * @param  array<string, mixed>  $options
     * @return array{bytes: string, mime: string, name: string}
     */
    public function preview(string $type, string $sourceId, array $options = []): array
    {
        $this->require('hr.documents.generate');
        $t = DocumentTypes::get($type);
        $source = $this->source($t, $sourceId);
        $payload = $t->build($source, (array) ($options['type'] ?? [])) + ['number' => $t->number($source) ?? '—'];
        $o = $this->options($t, $options);
        $this->audit->record('documents.previewed', $source, null, null, ['type' => $type]);

        return $this->output($o, $payload, null, __('documents.watermark.draft'), null, 'draft-'.$type);
    }

    /**
     * Issues the document of a final record, or returns the one already issued for it.
     *
     * @param  array<string, mixed>  $options  layout / template_id, language, digits, paper, toggles, and type options
     */
    public function issue(string $type, string $sourceId, array $options = []): GeneratedDocument
    {
        $this->require('hr.documents.generate');
        $t = DocumentTypes::get($type);

        return DB::transaction(function () use ($t, $type, $sourceId, $options): GeneratedDocument {
            $source = $this->source($t, $sourceId);
            if (! $t->isFinal($source)) {
                throw new BusinessRuleViolation('This record is not final yet: preview it as a draft; it can be issued once it is approved and finalized.');
            }
            if ($t->oncePerSource()) {
                $existing = $this->current($type, $source);
                if ($existing !== null) {
                    return $existing;
                }
            }
            $number = $t->number($source) ?? $this->numbers->next($t->series());
            $payload = $t->build($source, (array) ($options['type'] ?? [])) + ['number' => $number, 'revision' => 0];
            // The content is always stored masked; a certificate for a bank may be printed with the full CNIC by those allowed.
            $unmasked = ($options['unmask'] ?? false) ? $this->unmasked($t->employeeId($source)) : [];

            return $this->store($t, $source, $number, 0, $payload, $this->options($t, $options), null, null, $unmasked);
        });
    }

    /**
     * Issues the document for many records; those that fail are reported back with the reason.
     *
     * @param  list<string>  $sourceIds
     * @param  array<string, mixed>  $options
     * @return array{documents: list<GeneratedDocument>, failed: array<string, string>}
     */
    public function issueMany(string $type, array $sourceIds, array $options = []): array
    {
        $docs = [];
        $failed = [];
        foreach ($sourceIds as $id) {
            try {
                $docs[] = $this->issue($type, $id, $options);
            } catch (BusinessRuleViolation $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['documents' => $docs, 'failed' => $failed];
    }

    /**
     * A correction: the record's content is built again; if it differs, a new revision is issued that
     * replaces the current one (which stays visible and verifiable as replaced).
     */
    public function revise(GeneratedDocument $doc, string $reason): GeneratedDocument
    {
        $this->require('hr.documents.generate');
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for the correction.');
        }

        return DB::transaction(function () use ($doc, $reason): GeneratedDocument {
            $doc = GeneratedDocument::query()->lockForUpdate()->findOrFail($doc->id);
            if (! $doc->isCurrent()) {
                throw new BusinessRuleViolation('This document was already replaced; correct the latest revision.');
            }
            $t = DocumentTypes::get($doc->doc_type);
            $source = $this->source($t, $doc->source_id);
            $payload = $t->build($source, $doc->options['type'] ?? []) + ['number' => $doc->number];
            $old = $doc->payload;
            unset($old['revision'], $old['revision_reason']);
            if (CanonicalJson::encode($payload) === CanonicalJson::encode($old)) {
                throw new BusinessRuleViolation('Nothing in the record has changed since this document was issued; there is nothing to correct.');
            }
            $revision = $doc->revision + 1;
            $payload += ['revision' => $revision, 'revision_reason' => trim($reason)];

            return $this->store($t, $source, $doc->number, $revision, $payload, $this->options($t, ['type' => $doc->options['type'] ?? []], $doc), $doc, trim($reason));
        });
    }

    /**
     * The stored original, byte for byte (checked against its SHA-256 first).
     *
     * @return array{bytes: string, mime: string, name: string}
     */
    public function original(GeneratedDocument $doc, ?DocumentShare $share = null): array
    {
        $this->authorizeView($doc, $share);
        $bytes = $this->storedOriginal($doc);
        $this->log($doc, 'download', $share);

        // Half-A4 originals are kept one slip per page and printed in the top half of an A4 sheet.
        return ['bytes' => $doc->paper === 'half_a4' ? $this->renderer->impose([$bytes]) : $bytes, 'mime' => 'application/pdf', 'name' => $this->fileName($doc, 'pdf')];
    }

    /**
     * A reprint from the frozen content, in any options and format, watermarked DUPLICATE and counted.
     *
     * @param  array<string, mixed>  $options  layout, language, digits, paper, toggles, format (pdf|html|png|escpos), password, unmask
     * @return array{bytes: string, mime: string, name: string}
     */
    public function reprint(GeneratedDocument $doc, array $options = [], ?DocumentShare $share = null, bool $raw = false): array
    {
        $this->authorizeView($doc, $share);
        $t = DocumentTypes::get($doc->doc_type);
        $o = $this->options($t, $options + ['template_id' => $doc->templateVersion->template_id], $doc);
        $unmasked = ($options['unmask'] ?? false) && $share === null ? $this->unmasked($doc->employee_id) : [];
        $password = $this->password($doc, $options);
        $count = $doc->reprintCount() + 1;
        $this->log($doc, 'reprint', $share, ['format' => $o['format'], 'layout' => $o['layout'], 'language' => $o['language'], 'paper' => $o['paper'],
            'unmasked' => $unmasked !== [], 'password' => $password !== null, 'copy' => $count]);

        return $this->output($o, $doc->payload, $this->verify($doc), __('documents.watermark.duplicate', [], 'en').' #'.$count, $password,
            $this->fileName($doc, ''), $unmasked, $raw);
    }

    /**
     * Many issued documents in one PDF for printing (one per page, or two half-A4 slips per sheet).
     * Documents asked for as issued are the stored originals; any other layout, language or paper is a
     * counted DUPLICATE.
     *
     * @param  iterable<GeneratedDocument>  $docs
     * @param  array<string, mixed>  $options
     */
    public function combined(iterable $docs, array $options = []): string
    {
        $pdfs = [];
        $half = null;
        foreach ($docs as $doc) {
            $half ??= ($options['paper'] ?? $doc->paper) === 'half_a4';
            if ($this->asIssued($doc, $options)) {
                $this->authorizeView($doc);
                $pdfs[] = $this->storedOriginal($doc);
                $this->log($doc, 'download', null, ['bulk' => true]);
            } else {
                $pdfs[] = $this->reprint($doc, ['format' => 'pdf', 'password' => null] + $options, raw: true)['bytes'];
            }
        }
        if ($pdfs === []) {
            throw new BusinessRuleViolation('No documents to print.');
        }

        return $half ? $this->renderer->impose($pdfs) : $this->renderer->merge($pdfs);
    }

    /**
     * True when the options ask for the document exactly as it was issued (so the original can be given).
     *
     * @param  array<string, mixed>  $options
     */
    public function asIssued(GeneratedDocument $doc, array $options): bool
    {
        return ($options['format'] ?? 'pdf') === 'pdf' && ($options['layout'] ?? $doc->layout) === $doc->layout
            && ($options['language'] ?? $doc->language) === $doc->language && ($options['paper'] ?? $doc->paper) === $doc->paper
            && ($options['digits'] ?? ($doc->options['digits'] ?? 'western')) === ($doc->options['digits'] ?? 'western')
            && ! array_key_exists('toggles', $options) && ! ($options['unmask'] ?? false) && in_array($options['password'] ?? null, [null, '', 'none'], true);
    }

    /** The current (not replaced) document issued for a record. */
    public function current(string $type, Model $source): ?GeneratedDocument
    {
        return GeneratedDocument::query()->where('doc_type', $type)->where('source_id', $source->getKey())
            ->whereNotIn('id', GeneratedDocument::query()->whereNotNull('supersedes_id')->select('supersedes_id'))
            ->orderByDesc('revision')->first();
    }

    /** @param  array<string, mixed>  $meta */
    public function log(GeneratedDocument $doc, string $action, ?DocumentShare $share = null, array $meta = []): void
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        DocumentAccessLog::query()->create([
            'generated_document_id' => $doc->id, 'action' => $action, 'account_id' => $this->actor->accountId(), 'share_id' => $share?->id,
            'ip' => $request?->ip(), 'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'meta' => $meta === [] ? null : $meta, 'created_at' => now(),
        ]);
    }

    /** Who may see a document: the employee it belongs to, or staff allowed to generate documents (or through a valid share link). */
    public function authorizeView(GeneratedDocument $doc, ?DocumentShare $share = null): void
    {
        if ($share !== null) {
            if ($share->generated_document_id !== $doc->id || ! $share->isUsable()) {
                throw new BusinessRuleViolation('This link has expired or was revoked.');
            }

            return;
        }
        $account = $this->actor->account() ?? throw new BusinessRuleViolation('Sign in to see this document.');
        if ($account->hasPermission('hr.documents.generate')) {
            return;
        }
        $own = $doc->employee_id !== null && Employee::query()->whereKey($doc->employee_id)->where('person_id', $account->person_id)->exists();
        if (! $own) {
            throw new BusinessRuleViolation('You can see only your own documents.');
        }
    }

    public function verificationCode(GeneratedDocument $doc): string
    {
        return SlipRenderer::verificationCode($doc->id, ['number' => $doc->number, 'revision' => $doc->revision, 'sha' => hash('sha256', CanonicalJson::encode($doc->payload))]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $o  resolved options
     * @param  array<string, string>  $unmasked
     */
    private function store(DocumentType $t, Model $source, string $number, int $revision, array $payload, array $o, ?GeneratedDocument $supersedes, ?string $reason, array $unmasked = []): GeneratedDocument
    {
        if (GeneratedDocument::query()->where('doc_type', $t->key())->where('number', $number)->where('revision', $revision)->exists()) {
            throw new BusinessRuleViolation("Document {$number} is already issued.");
        }
        $id = (string) Str::uuid7();
        $code = SlipRenderer::verificationCode($id, ['number' => $number, 'revision' => $revision, 'sha' => hash('sha256', CanonicalJson::encode($payload))]);
        $display = $number.($revision > 0 ? ' R'.$revision : '');
        $pdf = $this->output(['format' => 'pdf'] + $o, $payload, ['doc' => $display, 'code' => $code], null, null, 'x', $unmasked, raw: true)['bytes'];
        $file = $this->files->put($pdf);

        $doc = new GeneratedDocument;
        $doc->forceFill(['id' => $id])->fill([
            'doc_type' => $t->key(), 'number' => $number, 'revision' => $revision, 'source_type' => class_basename($t->sourceClass()), 'source_id' => $source->getKey(),
            'employee_id' => $t->employeeId($source), 'template_version_id' => $o['template_version_id'], 'layout' => $o['layout'], 'language' => $o['language'],
            'paper' => $o['paper'], 'options' => ['digits' => $o['digits'], 'toggles' => $o['toggles'], 'type' => $o['type']], 'payload' => $payload,
            'file_id' => $file->id, 'sha256' => $file->sha256, 'verify_code' => $code, 'supersedes_id' => $supersedes?->id, 'reason' => $reason, 'created_at' => now(),
        ])->save();
        $this->log($doc, 'issue', null, array_filter(['replaces' => $supersedes?->id, 'reason' => $reason, 'unmasked' => $unmasked !== [] ? true : null]));
        $this->audit->record($supersedes !== null ? 'documents.revised' : 'documents.issued', $doc, null, null, ['type' => $t->key(), 'number' => $display]);

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $o  resolved options
     * @param  array<string, mixed>  $payload
     * @param  array{doc: string, code: string}|null  $verify
     * @param  array<string, string>  $unmasked
     * @return array{bytes: string, mime: string, name: string}
     */
    private function output(array $o, array $payload, ?array $verify, ?string $watermark, ?string $password, string $name, array $unmasked = [], bool $raw = false): array
    {
        $paper = $o['paper'];
        $v = [
            'payload' => $payload, 'settings' => $o['settings'], 'layout' => $o['layout'], 'language' => $o['language'], 'digits' => $o['digits'],
            'paper' => $paper, 'toggles' => $o['toggles'], 'entity' => Entity::query()->find($this->tenant->requireEntityId()), 'watermark' => $watermark,
            'verify' => $verify, 'unmasked' => $unmasked, 'printed_by' => $this->actor->account()?->username,
        ];

        return match ($o['format']) {
            'html' => ['bytes' => $this->renderer->html($v), 'mime' => 'text/html', 'name' => $name.'.html'],
            'png' => ['bytes' => $this->renderer->png($this->renderer->pdf($v, null, impose: false)), 'mime' => 'image/png', 'name' => $name.'.png'],
            'escpos' => ['bytes' => $this->renderer->escpos($v), 'mime' => 'application/octet-stream', 'name' => $name.'.prn'],
            default => ['bytes' => $this->renderer->pdf($v, $password, impose: ! $raw), 'mime' => 'application/pdf', 'name' => $name.'.pdf'],
        };
    }

    /**
     * Full CNIC and bank account of the document's employee, for users allowed to see them.
     *
     * @return array<string, string>
     */
    private function unmasked(?string $employeeId): array
    {
        if ($employeeId === null) {
            return [];
        }
        $this->require('hr.employee.sensitive.view');
        $e = Employee::query()->findOrFail($employeeId);

        return ['cnic' => PakistanIds::formatCnic($e->cnic) ?? '—', 'iban' => (string) ($e->bank_account_number ?? '—')];
    }

    private function storedOriginal(GeneratedDocument $doc): string
    {
        $bytes = (string) Storage::disk('local')->get($doc->file->path);
        if (! hash_equals($doc->sha256, hash('sha256', $bytes))) {
            throw new BusinessRuleViolation("The stored file of {$doc->displayNumber()} no longer matches what was issued; it cannot be given out. Reprint it from the record instead.");
        }

        return $bytes;
    }

    /**
     * Options for one rendering: what was asked, else what the document was issued with, else the template's.
     *
     * @param  array<string, mixed>  $asked
     * @return array<string, mixed>
     */
    private function options(DocumentType $t, array $asked, ?GeneratedDocument $doc = null): array
    {
        $layout = $asked['layout'] ?? null;
        if ($layout !== null && ! array_key_exists($layout, DocumentTemplate::LAYOUTS)) {
            throw new BusinessRuleViolation('Unknown layout.');
        }
        $version = $layout !== null
            ? $this->templates->resolve($t->key(), $layout)
            : ($doc->templateVersion ?? $this->templates->resolve($t->key(), null, $asked['template_id'] ?? null));
        $paper = $asked['paper'] ?? $doc->paper ?? $t->defaultPaper();
        if (! array_key_exists($paper, DocumentRenderer::PAPERS)) {
            throw new BusinessRuleViolation('Unknown paper size.');
        }
        $language = $asked['language'] ?? $doc->language ?? 'bilingual';
        if (! array_key_exists($language, DocumentPresenter::LANGUAGES)) {
            throw new BusinessRuleViolation('Unknown language.');
        }
        $format = $asked['format'] ?? 'pdf';
        if (! array_key_exists($format, DocumentRenderer::FORMATS)) {
            throw new BusinessRuleViolation('Unknown output format.');
        }
        $toggles = (array) ($version->settings['toggles'] ?? TemplateRepository::DEFAULT_SETTINGS['toggles']);
        foreach ((array) ($doc->options['toggles'] ?? []) as $k => $on) {
            $toggles[$k] = (bool) $on;
        }
        foreach ((array) ($asked['toggles'] ?? []) as $k => $on) {
            if (array_key_exists($k, TemplateRepository::TOGGLES)) {
                $toggles[$k] = (bool) $on;
            }
        }

        return [
            'template_version_id' => $version->id, 'settings' => $version->settings, 'layout' => $layout ?? $version->template->layout,
            'language' => $language, 'digits' => ($asked['digits'] ?? $doc->options['digits'] ?? 'western') === 'urdu' ? 'urdu' : 'western',
            'paper' => $paper, 'toggles' => $toggles, 'format' => $format, 'type' => (array) ($asked['type'] ?? []),
        ];
    }

    /**
     * cnic5 = the last five digits of the employee's CNIC; pin = a PIN given at download (4–12 digits).
     *
     * @param  array<string, mixed>  $options
     */
    private function password(GeneratedDocument $doc, array $options): ?string
    {
        return match ($options['password'] ?? null) {
            null, '', 'none' => null,
            'cnic5' => ($cnic = Employee::query()->find($doc->employee_id)?->cnic) !== null && strlen((string) preg_replace('/\D/', '', $cnic)) >= 5
                ? substr((string) preg_replace('/\D/', '', $cnic), -5) : throw new BusinessRuleViolation('This employee has no CNIC on file; use a PIN.'),
            'pin' => preg_match('/^\d{4,12}$/', (string) ($options['pin'] ?? '')) === 1 ? (string) $options['pin'] : throw new BusinessRuleViolation('The PIN is 4 to 12 digits.'),
            default => throw new BusinessRuleViolation('Unknown password choice.'),
        };
    }

    /** @return array{doc: string, code: string} */
    private function verify(GeneratedDocument $doc): array
    {
        return ['doc' => $doc->displayNumber(), 'code' => $doc->verify_code];
    }

    private function fileName(GeneratedDocument $doc, string $ext): string
    {
        $base = $doc->doc_type.'-'.preg_replace('/[^A-Za-z0-9-]+/', '-', $doc->displayNumber());

        return $ext === '' ? $base : $base.'.'.$ext;
    }

    private function source(DocumentType $t, string $id): Model
    {
        $class = $t->sourceClass();

        return $class::query()->find($id) ?? throw new BusinessRuleViolation('The record of this document was not found.');
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("This needs the permission {$permission}.");
        }
    }
}
