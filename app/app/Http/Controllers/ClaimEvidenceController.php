<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Files\FileStore;
use App\Models\Claims\Claim;
use App\Models\StoredFile;
use Filament\Facades\Filament;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * GET /admin/claims/{id}/evidence/{sha256}: opens one photo or document of a claim (inline) for
 * someone who may see the claim. Only hashes recorded on that claim are served, and the bytes are
 * checked against the hash first, so an altered file is never shown as evidence.
 */
final class ClaimEvidenceController
{
    public function __invoke(string $id, string $sha256, FileStore $files): Response
    {
        $claim = Claim::query()->find($id) ?? abort(404);
        abort_unless(Filament::auth()->user()?->can('view', $claim) ?? false, 403);
        abort_unless(in_array($sha256, (array) $claim->evidence, true), 404);

        $file = StoredFile::query()->where('sha256', $sha256)->first() ?? abort(404);
        abort_unless($files->verify($file), 409, 'This file no longer matches its recorded fingerprint.');

        $name = 'claim-'.$claim->number.'-'.substr($sha256, 0, 8).match ($file->mime) {
            'application/pdf' => '.pdf', 'image/png' => '.png', 'image/jpeg' => '.jpg', default => '',
        };

        return response((string) Storage::disk('local')->get($file->path), 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
