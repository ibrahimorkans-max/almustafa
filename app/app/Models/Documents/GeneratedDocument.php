<?php

declare(strict_types=1);

namespace App\Models\Documents;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Hr\Employee;
use App\Models\StoredFile;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A document as issued: its number, the template version, the options, and the frozen content
 * (payload) it was rendered from, with the stored original PDF and its SHA-256. Append-only. A
 * correction is a new revision pointing at the one it replaces; the original stays visible.
 * Reprints are rendered from the same payload with a DUPLICATE watermark and counted in the log.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $doc_type
 * @property string $number
 * @property int $revision
 * @property string $source_type
 * @property string $source_id
 * @property string|null $employee_id
 * @property string $template_version_id
 * @property string $layout
 * @property string $language
 * @property string $paper
 * @property array<string, mixed> $options
 * @property array<string, mixed> $payload
 * @property string $file_id
 * @property string $sha256
 * @property string $verify_code
 * @property string|null $supersedes_id
 * @property string|null $reason
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Employee|null $employee
 * @property-read StoredFile $file
 * @property-read DocumentTemplateVersion $templateVersion
 * @property-read GeneratedDocument|null $supersededBy
 */
class GeneratedDocument extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    protected $fillable = [
        'doc_type', 'number', 'revision', 'source_type', 'source_id', 'employee_id', 'template_version_id', 'layout', 'language', 'paper',
        'options', 'payload', 'file_id', 'sha256', 'verify_code', 'supersedes_id', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'options' => 'array', 'payload' => 'array', 'created_at' => 'datetime'];
    }

    /** The number as printed: "PSL/2026-27/0001", a revision as "… R1". */
    public function displayNumber(): string
    {
        return $this->number.($this->revision > 0 ? ' R'.$this->revision : '');
    }

    public function isCurrent(): bool
    {
        return ! self::query()->where('supersedes_id', $this->id)->exists();
    }

    public function reprintCount(): int
    {
        return DocumentAccessLog::query()->where('generated_document_id', $this->id)->where('action', 'reprint')->count();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    /** @return BelongsTo<DocumentTemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'template_version_id');
    }

    /** @return HasOne<GeneratedDocument, $this> */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /** @return HasMany<DocumentAccessLog, $this> */
    public function accessLog(): HasMany
    {
        return $this->hasMany(DocumentAccessLog::class);
    }

    /** @return HasMany<DocumentShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class);
    }
}
