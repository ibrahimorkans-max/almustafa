<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\PaperSize;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** POST /admin/print-paper: remembers on this device which paper slips and reports print on. */
final class PaperSizeController
{
    public function __invoke(Request $request): Response
    {
        $paper = (string) $request->input('paper', '');
        abort_unless($paper === '' || isset(PaperSize::OPTIONS[$paper]), 422);

        return $paper === ''
            ? response()->noContent()->withoutCookie(PaperSize::COOKIE)
            : response()->noContent()->cookie(PaperSize::COOKIE, $paper, 60 * 24 * 365, null, null, null, true, false, 'lax');
    }
}
