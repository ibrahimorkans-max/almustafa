<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\PdfRenderer;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** GET /admin/branding/preview: a sample document with the working mill's letterhead, notes and bank, on the paper chosen for this device. */
final class BrandingPreviewController
{
    public function __invoke(TenantContext $tenant, PdfRenderer $pdf): Response
    {
        $id = $tenant->entityId();
        $mill = $id === null ? null : Entity::query()->find($id);
        abort_if($mill === null, 404);
        abort_unless(Gate::allows('update', $mill), 403);

        $bytes = $pdf->render('pdf.brand-sample', ['title' => 'Sample document'], $mill);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="sample.pdf"']);
    }
}
