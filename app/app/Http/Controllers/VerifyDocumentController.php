<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentVerifier;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public page: is this printed slip / invoice / gate pass genuine? */
final class VerifyDocumentController extends Controller
{
    public function __invoke(Request $request, DocumentVerifier $verifier): View
    {
        $data = $request->validate(['doc' => ['nullable', 'string', 'max:60'], 'code' => ['nullable', 'string', 'max:20']]);
        $asked = filled($data['doc'] ?? null) && filled($data['code'] ?? null);

        return view('verify', [
            'doc' => $data['doc'] ?? '',
            'code' => $data['code'] ?? '',
            'asked' => $asked,
            'result' => $asked ? $verifier->verify((string) $data['doc'], (string) $data['code']) : null,
        ]);
    }
}
