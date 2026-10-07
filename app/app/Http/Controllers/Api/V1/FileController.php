<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\LoginAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Photo / document upload for devices. Idempotent: the same bytes return the same sha256. */
final class FileController extends Controller
{
    public function store(Request $request, FileStore $files): JsonResponse
    {
        /** @var LoginAccount $account */
        $account = $request->user();
        abort_unless(
            collect(['gate.entry', 'weighment.capture', 'lab.test', 'master.manage'])->contains(fn ($p) => $account->hasPermission($p)),
            403,
        );
        // Two forms: multipart field "file" (browsers), or the raw bytes as the request body
        // (gate agent: PHP does not keep a multipart body, so only a raw body can be signed).
        if ($request->hasFile('file')) {
            $request->validate(['file' => ['file', 'max:5120']]);
            $bytes = (string) file_get_contents($request->file('file')->getRealPath());
        } else {
            $bytes = $request->getContent();
        }

        try {
            $stored = $files->put($bytes);
        } catch (BusinessRuleViolation $e) {
            return response()->json(['error' => 'file_rejected', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['sha256' => $stored->sha256, 'mime' => $stored->mime, 'size_bytes' => $stored->size_bytes], 201);
    }
}
