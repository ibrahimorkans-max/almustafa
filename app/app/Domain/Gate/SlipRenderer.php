<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Audit\CanonicalJson;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\Weighment;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Weighment slip as PDF, rendered from the slip_issued event snapshot (what was true when the
 * slip was issued), with a verification code: anyone holding the slip can have the office
 * re-compute the code from the stored snapshot and detect an altered printout.
 */
final class SlipRenderer
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function pdf(GateEntry $entry): string
    {
        $slip = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'slip_issued')->first()
            ?? throw new BusinessRuleViolation('No slip has been issued for this vehicle.');

        $weighments = Weighment::query()->where('gate_entry_id', $entry->id)->orderBy('captured_at')->get();
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($entry->entity_id));

        return Pdf::loadView('slips.weighment', [
            'entity' => $entity,
            'entry' => $entry->loadMissing(['party', 'product', 'sauda']),
            'slip' => $slip->data,
            'issuedAt' => $slip->occurred_at,
            'weighments' => $weighments,
            'code' => self::verificationCode($entry->id, $slip->data ?? []),
        ])->setPaper('a5')->output();
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function verificationCode(string $gateEntryId, array $snapshot): string
    {
        $mac = hash_hmac('sha256', $gateEntryId.'|'.CanonicalJson::encode($snapshot), (string) config('app.key'));

        return strtoupper(implode('-', str_split(substr($mac, 0, 12), 4)));
    }
}
