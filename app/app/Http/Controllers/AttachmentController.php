<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Files\Attachments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Attachment;
use Illuminate\Http\Response;

/**
 * GET /admin/attachments/{id}: opens an attached file (inline) for someone who may see the record it
 * belongs to; the file is checked against its SHA-256 first.
 */
final class AttachmentController
{
    public function __invoke(string $id, Attachments $attachments): Response
    {
        $attachment = Attachment::query()->find($id) ?? abort(404);
        try {
            $file = $attachments->open($attachment);
        } catch (BusinessRuleViolation $e) {
            abort(403, $e->getMessage());
        }

        return response($file['bytes'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'inline; filename="'.$file['name'].'"',
            'X-Content-Type-Options' => 'nosniff']);
    }
}
