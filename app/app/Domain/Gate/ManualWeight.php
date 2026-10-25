<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Auth\ActorContext;
use App\Domain\Files\Attachments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;

/**
 * One of a vehicle's two weights typed by hand — e.g. the empty weight known from another weighbridge's
 * slip — while the other comes from this weighbridge's computer. Only with `weighment.manual`
 * (Supervisor, Super Admin, Owner), always with a reason; the other weighbridge's slip can be uploaded
 * as evidence (kept, fingerprinted, attached to the vehicle). No approval is needed: the weight counts at
 * once, is flagged "typed by hand" everywhere and goes to the weight checks. At most one of the two
 * weights may be typed.
 */
final class ManualWeight
{
    public function __construct(
        private readonly GateService $gate,
        private readonly Attachments $attachments,
        private readonly ActorContext $actor,
    ) {}

    public function record(GateEntry $entry, string $kind, string $weightKg, string $reason, ?string $evidenceBytes = null): Weighment
    {
        $me = $this->actor->account();
        if (! $me instanceof LoginAccount || ! $me->hasPermission('weighment.manual')) {
            throw new BusinessRuleViolation('You may not type a weight by hand.');
        }
        if (! in_array($kind, [Weighment::GROSS, Weighment::TARE], true)) {
            throw new BusinessRuleViolation('Choose the loaded (gross) or the empty (tare) weight.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new BusinessRuleViolation('Give the reason for typing the weight (e.g. "empty weight from Kanta 2 slip no. 4512").');
        }
        if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', trim($weightKg))) {
            throw new BusinessRuleViolation('Type the weight in kg, e.g. 15240.');
        }
        $other = $kind === Weighment::GROSS ? Weighment::TARE : Weighment::GROSS;
        $otherWeight = self::current($entry, $other);
        if ($otherWeight !== null && $otherWeight->source === Weighment::SOURCE_MANUAL) {
            throw new BusinessRuleViolation('The '.self::label($entry, $other).' was typed by hand, so this one must come from the weighbridge.');
        }

        $evidence = null;
        if ($evidenceBytes !== null && $evidenceBytes !== '') {
            $evidence = $this->attachments->attach($entry, 'weighbridge_slip', $evidenceBytes, 'Evidence for the typed '.self::label($entry, $kind).': '.$reason);
        }

        return $this->gate->recordWeighment(
            $entry, $kind, trim($weightKg), null,
            ['manual_entry' => true, 'evidence_attachment_id' => $evidence?->id, 'evidence_sha256' => $evidence?->file->sha256],
            null,
            source: Weighment::SOURCE_MANUAL,
            reason: $reason,
            extraFlags: $evidence === null ? ['manual_entry', 'no_slip_evidence'] : ['manual_entry'],
        );
    }

    /** The weight of this kind that counts now (the last one, re-weighs replace earlier ones). */
    public static function current(GateEntry $entry, string $kind): ?Weighment
    {
        return Weighment::query()->where('gate_entry_id', $entry->id)->where('kind', $kind)
            ->orderByDesc('captured_at')->orderByDesc('created_at')->first();
    }

    /** "loaded weight" / "empty weight" — gross is loaded and tare is empty, both ways. */
    public static function label(GateEntry $entry, string $kind): string
    {
        return $kind === Weighment::GROSS ? 'loaded weight (gross)' : 'empty weight (tare)';
    }
}
