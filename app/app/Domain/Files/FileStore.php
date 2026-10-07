<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;

/**
 * Content-addressed private storage. The SHA-256 of the bytes is the identity: a weighment
 * stores the hash of its photo, so the photo can be proven unchanged later. Files live on the
 * private disk and are served only through authorised routes.
 */
final class FileStore
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
    ) {}

    public function put(string $bytes): StoredFile
    {
        $size = strlen($bytes);
        if ($size === 0 || $size > StoredFile::MAX_BYTES) {
            throw new BusinessRuleViolation('File is empty or larger than 5 MB.');
        }

        // Trust the content, not the client's claimed type.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (! in_array($mime, StoredFile::ALLOWED_MIME, true)) {
            throw new BusinessRuleViolation("File type {$mime} is not allowed.");
        }

        $sha = hash('sha256', $bytes);
        $existing = StoredFile::query()->where('sha256', $sha)->first();
        if ($existing !== null) {
            return $existing;
        }

        $entityId = $this->tenant->requireEntityId();
        $path = sprintf('files/%s/%s/%s', $entityId, substr($sha, 0, 2), $sha);
        Storage::disk('local')->put($path, $bytes);

        return StoredFile::query()->create([
            'sha256' => $sha,
            'mime' => $mime,
            'size_bytes' => $size,
            'path' => $path,
            'device_id' => $this->actor->deviceId(),
        ]);
    }

    public function exists(string $sha256): bool
    {
        return StoredFile::query()->where('sha256', $sha256)->exists();
    }

    /** Re-hashes the stored bytes: proves the file was not altered after upload. */
    public function verify(StoredFile $file): bool
    {
        $bytes = Storage::disk('local')->get($file->path);

        return $bytes !== null && hash_equals($file->sha256, hash('sha256', $bytes));
    }
}
