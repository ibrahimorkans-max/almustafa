<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Jobs\GenerateDocumentBatch;
use App\Models\Documents\DocumentBatch;
use App\Models\Documents\GeneratedDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Bulk generation: all employees of a month, a department or a selection. The work runs on the
 * queue, one record at a time with progress saved after each, so a retry continues where it stopped.
 * A record that cannot be issued is listed with the reason and does not stop the others. The batch
 * ends with one PDF to print (two half-A4 slips per sheet) and a ZIP of the stored originals.
 */
final class DocumentBatches
{
    /** One batch makes at most this many documents (well inside the 30-minute job), and a mill runs at most two at once. */
    public const MAX_RECORDS = 1000;

    public const MAX_ACTIVE = 2;

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentService $documents,
    ) {}

    /**
     * @param  list<string>  $sourceIds
     * @param  array<string, mixed>  $options
     */
    public function start(string $type, array $sourceIds, string $label, array $options = []): DocumentBatch
    {
        try {
            $this->documents->requireFor($type);
        } catch (BusinessRuleViolation $e) {
            throw new BusinessRuleViolation('Bulk generation: '.lcfirst($e->getMessage()));
        }
        DocumentTypes::get($type);
        $sourceIds = array_values(array_unique($sourceIds));
        if ($sourceIds === []) {
            throw new BusinessRuleViolation('Nothing to generate.');
        }
        if (count($sourceIds) > self::MAX_RECORDS) {
            throw new BusinessRuleViolation(sprintf('At most %d documents in one batch; split it, for example by department.', self::MAX_RECORDS));
        }
        // A batch untouched for two hours (three 30-minute tries and their pauses) is not counted as running.
        if (DocumentBatch::query()->whereIn('status', [DocumentBatch::QUEUED, DocumentBatch::RUNNING])->where('updated_at', '>=', now()->subHours(2))->count() >= self::MAX_ACTIVE) {
            throw new BusinessRuleViolation('Two bulk runs are already going; start this one when one of them has finished.');
        }
        $batch = DocumentBatch::query()->create(['doc_type' => $type, 'label' => mb_substr($label, 0, 120), 'source_ids' => $sourceIds,
            'options' => $options, 'total' => count($sourceIds)]);
        $this->audit->record('documents.batch_started', $batch, null, null, ['type' => $type, 'count' => count($sourceIds)]);
        GenerateDocumentBatch::dispatch($batch->id);

        return $batch;
    }

    /** A new batch for the records that failed, after their cause has been fixed. */
    public function retryFailed(DocumentBatch $batch): DocumentBatch
    {
        $failed = array_keys($batch->failed ?? []);
        if ($failed === []) {
            throw new BusinessRuleViolation('Nothing failed in this batch.');
        }

        return $this->start($batch->doc_type, $failed, $batch->label.' (retry)', $batch->options);
    }

    /** Runs (or continues) the batch; called by the queued job. */
    public function run(DocumentBatch $batch): void
    {
        $batch->forceFill(['status' => DocumentBatch::RUNNING, 'started_at' => $batch->started_at ?? now(), 'attempts' => $batch->attempts + 1, 'error' => null])->save();
        $docs = $batch->document_ids ?? [];
        $failed = $batch->failed ?? [];
        try {
            foreach (array_slice($batch->source_ids, $batch->done_count) as $sourceId) {
                try {
                    $docs[] = $this->documents->issue($batch->doc_type, $sourceId, $batch->options)->id;
                    unset($failed[$sourceId]);
                } catch (BusinessRuleViolation $e) {
                    $failed[$sourceId] = $e->getMessage();
                }
                $batch->forceFill(['done_count' => $batch->done_count + 1, 'document_ids' => array_values(array_unique($docs)), 'failed' => $failed === [] ? null : $failed])->save();
            }
            $this->package($batch);
            $batch->forceFill(['status' => DocumentBatch::DONE, 'finished_at' => now()])->save();
            $this->audit->record('documents.batch_done', $batch, null, null, ['issued' => count($docs), 'failed' => count($failed)]);
        } catch (Throwable $e) {
            $batch->forceFill(['status' => DocumentBatch::FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            throw $e;
        }
    }

    /**
     * @return array{bytes: string, mime: string, name: string}
     */
    public function download(DocumentBatch $batch, string $what): array
    {
        if (! ($this->actor->account()?->hasPermission('hr.documents.generate') ?? false)) {
            throw new BusinessRuleViolation('This needs the permission hr.documents.generate.');
        }
        $path = $what === 'zip' ? $batch->zip_path : $batch->pdf_path;
        if ($batch->status !== DocumentBatch::DONE || $path === null) {
            throw new BusinessRuleViolation('The batch has not finished yet.');
        }
        foreach ($this->documentsOf($batch) as $doc) {
            $this->documents->log($doc, 'download', null, ['batch' => $batch->id, 'as' => $what]);
        }
        $name = preg_replace('/[^A-Za-z0-9-]+/', '-', $batch->label) ?: 'documents';

        return ['bytes' => (string) Storage::disk('local')->get($path), 'mime' => $what === 'zip' ? 'application/zip' : 'application/pdf', 'name' => $name.'.'.$what];
    }

    private function package(DocumentBatch $batch): void
    {
        $docs = $this->documentsOf($batch);
        if ($docs === []) {
            return;
        }
        $dir = 'documents/batches/'.$batch->entity_id;
        $pdf = $this->documents->combined($docs, array_intersect_key($batch->options, array_flip(['paper'])));
        Storage::disk('local')->put("{$dir}/{$batch->id}.pdf", $pdf);

        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the ZIP file.');
        }
        foreach ($docs as $doc) {
            $bytes = (string) Storage::disk('local')->get($doc->file->path);
            $code = $doc->employee !== null ? $doc->employee->code.'-' : '';
            $zip->addFromString($code.preg_replace('/[^A-Za-z0-9-]+/', '-', $doc->displayNumber()).'.pdf', $bytes);
        }
        $zip->close();
        Storage::disk('local')->put("{$dir}/{$batch->id}.zip", (string) file_get_contents($tmp));
        @unlink($tmp);
        $batch->forceFill(['pdf_path' => "{$dir}/{$batch->id}.pdf", 'zip_path' => "{$dir}/{$batch->id}.zip"])->save();
    }

    /** @return list<GeneratedDocument> in the batch's order */
    private function documentsOf(DocumentBatch $batch): array
    {
        $ids = $batch->document_ids ?? [];
        $byId = GeneratedDocument::query()->with('employee')->whereKey($ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (string $id) => $byId->get($id), $ids)));
    }
}
