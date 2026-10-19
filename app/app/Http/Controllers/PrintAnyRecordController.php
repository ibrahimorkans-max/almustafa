<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assistant\Screens;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\ScreenPrinter;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * GET /admin/print-record/{resource}/{id}: any record of any screen as a PDF (its own paper when it
 * has one, otherwise a sheet of its fields). Used by the assistant's Print links. The person must be
 * allowed to open that record.
 */
final class PrintAnyRecordController
{
    public function __invoke(string $resource, string $id, ScreenPrinter $printer, AuditLogger $audit): Response
    {
        $class = Screens::byKey($resource) ?? abort(404);
        $record = $class::getEloquentQuery()->whereKey($id)->first() ?? abort(404);
        abort_unless($class::canView($record) || $class::canEdit($record), 403);

        $title = $class::getModelLabel().' '.Screens::label($record);
        $pdf = $printer->sheet($record, $title, Screens::details($record));
        $audit->record('document.printed', $record, meta: ['screen' => $class]);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.Str::slug($title).'.pdf"']);
    }
}
