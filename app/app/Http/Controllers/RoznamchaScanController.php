<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Files\FileStore;
use App\Models\Accounts\RoznamchaPage;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/** GET /admin/roznamcha/{id}/scan: the original scanned page, checked against its SHA-256, for someone with the books. */
final class RoznamchaScanController
{
    public function __invoke(string $id, FileStore $files): Response
    {
        $me = Filament::auth()->user();
        abort_unless($me instanceof LoginAccount && ($me->hasPermission('ledger.view') || $me->hasPermission('ledger.post')), 403);
        $page = RoznamchaPage::query()->with('scan')->find($id) ?? abort(404);
        $file = $page->scan ?? abort(404);
        abort_unless($files->verify($file), 409, 'This scan no longer matches its recorded fingerprint.');

        return response((string) Storage::disk('local')->get($file->path), 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'inline; filename="'.str_replace('/', '-', $page->number).match ($file->mime) {
                'application/pdf' => '.pdf', 'image/png' => '.png', default => '.jpg',
            }.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
