<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\PaperSize;
use App\Domain\Documents\PdfRenderer;
use App\Models\Gate\GateEntry;
use App\Support\Values\PakistanIds;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** GET /admin/gate/token/{id}: the token slip (token number and vehicle number only), on the small roll unless chosen otherwise. */
final class TokenSlipController
{
    public function __invoke(string $id, PdfRenderer $pdf): Response
    {
        $entry = GateEntry::query()->find($id) ?? abort(404);
        abort_unless(Gate::allows('view', $entry), 403);
        $vehicle = $entry->no_plate ? 'No plate' : PakistanIds::formatVehicleNumber($entry->registration_no);
        $bytes = $pdf->render('gate.token-slip', ['title' => 'Token '.$entry->token_no, 'entry' => $entry, 'vehicle' => $vehicle, 'bare' => true],
            null, 'A5', paper: PaperSize::current() ?? 'roll');

        return response($bytes, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="token-'.$entry->token_no.'.pdf"']);
    }
}
