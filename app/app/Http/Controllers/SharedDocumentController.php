<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Engine\DocumentSharing;
use Illuminate\Http\Response;

/** Public: opens a shared document link (WhatsApp / SMS / e-mail). Expired, used-up or revoked links get a plain refusal. */
final class SharedDocumentController extends Controller
{
    public function __invoke(string $token, DocumentSharing $sharing): Response
    {
        $out = preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1 ? $sharing->open($token) : null;
        if ($out === null) {
            return response()->view('documents.link-closed', [], 410)->header('Cache-Control', 'no-store');
        }

        return response($out['bytes'], 200, [
            'Content-Type' => $out['mime'],
            'Content-Disposition' => 'inline; filename="'.$out['name'].'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
