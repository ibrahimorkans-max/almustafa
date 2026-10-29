<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\PartyStatementSharing;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public: a party statement opened from a shared link (WhatsApp). The link is signed and expires
 * (PartyStatementSharing::link): an altered or old link gets a plain refusal. The statement is built
 * inside the party's own mill only, and nothing is shown once the mill's access is stopped.
 */
final class SharedStatementController
{
    public function __invoke(Request $request, TenantContext $tenant, PartyStatementSharing $sharing): Response
    {
        $party = $tenant->runAsSystem(fn () => Party::query()->find((string) $request->query('party')));
        if ($party === null || app(CustomerAccess::class)->millBlocked($party->entity_id)) {
            return response()->view('documents.link-closed', [], 410)->header('Cache-Control', 'no-store');
        }
        $filters = json_decode((string) base64_decode((string) $request->query('f', ''), true), true);
        $from = CarbonImmutable::parse((string) $request->query('from'), 'Asia/Karachi');
        $to = CarbonImmutable::parse((string) $request->query('to'), 'Asia/Karachi');

        $pdf = $tenant->runInEntity($party->entity_id, fn () => $sharing->pdf($party, $from, $to, is_array($filters) ? PartyStatementSharing::cleanFilters($filters) : []));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.PartyStatementSharing::fileName($party, $to).'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
