<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\PaperSize;
use App\Domain\Documents\PdfRenderer;
use App\Models\Gate\GateEntry;
use App\Support\Values\PakistanIds;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * GET /admin/gate/token/{id}: the gate entry token (mill, token number, party, vehicle, type, date, time), on
 * the small roll unless chosen otherwise (?paper=roll|roll50|a5|a4). ?download=1 saves it as a PDF.
 */
final class TokenSlipController
{
    public function __invoke(Request $request, string $id, PdfRenderer $pdf): Response
    {
        $entry = GateEntry::query()->find($id) ?? abort(404);
        abort_unless(Gate::allows('view', $entry), 403);
        $vehicle = $entry->no_plate ? 'No plate' : PakistanIds::formatVehicleNumber($entry->registration_no);
        $bytes = $pdf->render('gate.token-slip', ['title' => 'Token '.$entry->tokenLabel(), 'entry' => $entry, 'vehicle' => $vehicle, 'bare' => true],
            null, 'A5', paper: PaperSize::resolve('gate.token-slip', $entry->entity_id) ?? 'roll');

        return response($bytes, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="token-'.$entry->tokenLabel().'.pdf"', 'X-Print-Frame' => '1']);
    }
}
