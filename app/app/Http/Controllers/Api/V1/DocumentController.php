<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\Engine\DocumentService;
use App\Domain\Documents\Engine\DocumentTypes;
use App\Domain\Hr\Attendance\AttendanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Documents\GeneratedDocument;
use App\Models\LoginAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Self-service from the employee's phone: my documents (current revisions) and their PDFs. Own documents only. */
final class DocumentController extends Controller
{
    public function me(Request $request, AttendanceService $attendance): JsonResponse
    {
        $employee = $attendance->employeeFor($this->account($request));
        if ($employee === null) {
            return response()->json(['documents' => [], 'message' => 'This login is not linked to an employee record.']);
        }

        return response()->json(['documents' => GeneratedDocument::query()->where('employee_id', $employee->id)
            ->whereNotIn('id', GeneratedDocument::query()->whereNotNull('supersedes_id')->select('supersedes_id'))
            ->orderByDesc('created_at')->limit(60)->get()
            ->map(fn (GeneratedDocument $d) => [
                'id' => $d->id, 'type' => $d->doc_type, 'title' => DocumentTypes::options()[$d->doc_type] ?? $d->doc_type,
                'number' => $d->displayNumber(), 'period' => $d->payload['period'] ?? null, 'issued_at' => $d->created_at->toIso8601String(),
                'verify_code' => $d->verify_code,
            ])->all()]);
    }

    public function pdf(Request $request, string $document, DocumentService $documents): Response
    {
        $this->account($request);
        $doc = GeneratedDocument::query()->find($document);
        abort_if($doc === null, 404);
        try {
            $out = $documents->original($doc);
        } catch (BusinessRuleViolation $e) {
            abort(403, $e->getMessage());
        }

        return response($out['bytes'], 200, ['Content-Type' => $out['mime'], 'Content-Disposition' => 'inline; filename="'.$out['name'].'"', 'Cache-Control' => 'no-store, private']);
    }

    private function account(Request $request): LoginAccount
    {
        $account = $request->user();
        abort_unless($account instanceof LoginAccount, 401);

        return $account;
    }
}
