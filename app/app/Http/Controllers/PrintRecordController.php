<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\RecordDocuments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * GET /admin/print/{type}/{id}: the printed copy of a record (voucher, expense, claim, sauda,
 * dispatch order, stores issue slip, production batch), opened in the browser for printing.
 * The record is looked up inside the working mill, and the person needs the right to see it.
 */
final class PrintRecordController
{
    public function __invoke(string $type, string $id, RecordDocuments $documents, AuditLogger $audit): Response
    {
        [$model, $label, $permission] = RecordDocuments::TYPES[$type] ?? abort(404);
        $user = Filament::auth()->user();
        abort_unless($user instanceof LoginAccount && $user->hasPermission($permission), 403);
        $record = $model::query()->find($id) ?? abort(404);

        try {
            $pdf = $documents->pdf($type, $record);
        } catch (BusinessRuleViolation $e) {
            abort(409, $e->getMessage());
        }
        $audit->record('document.printed', $record, meta: ['type' => $type]);
        $name = Str::slug($label.' '.($record->getAttribute('number') ?? $id)).'.pdf';

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"']);
    }
}
