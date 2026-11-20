<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Auth\ActorContext;
use App\Domain\Files\Attachments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Attachment;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use App\Support\Values\Weight;

/**
 * One of a vehicle's two weights typed by hand — e.g. the empty weight known from another weighbridge's
 * slip — while the other comes from this weighbridge's computer (or both, when the Owner allows it: Gate →
 * Manual weighing). Only for the people the Owner ticked there, else those with `weighment.manual`
 * (Supervisor, Super Admin, Owner), always with a reason; the other weighbridge's slip can be uploaded
 * as evidence (kept, fingerprinted, attached to the vehicle). No approval is needed: the weight counts at
 * once, is flagged "typed by hand" everywhere and goes to the weight checks.
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
        $this->check($entry, $kind, $weightKg, $reason);
        $evidence = $this->evidence($entry, $kind, $reason, $evidenceBytes);

        return $this->gate->recordWeighment(
            $entry, $kind, trim($weightKg), null,
            ['manual_entry' => true, 'evidence_attachment_id' => $evidence?->id, 'evidence_sha256' => $evidence?->file->sha256],
            null,
            source: Weighment::SOURCE_MANUAL,
            reason: trim($reason),
            extraFlags: $evidence === null ? ['manual_entry', 'no_slip_evidence'] : ['manual_entry'],
        );
    }

    /**
     * A weight the vehicle already has — its empty weight or its loaded weight, from another weighbridge's slip —
     * typed whenever it is known, even at arrival. When it is the vehicle's next weight it is recorded now; otherwise it
     * is noted on the vehicle and recorded by itself when that step comes, so the vehicle is weighed only once here.
     *
     * @return Weighment|null the weight recorded now, or null when it is noted for later
     */
    public function noteKnown(GateEntry $entry, string $kind, string $weightKg, string $reason, ?string $evidenceBytes = null): ?Weighment
    {
        if (LiveCapture::nextKind($entry) === $kind) {
            return $this->record($entry, $kind, $weightKg, $reason, $evidenceBytes);
        }
        $this->check($entry, $kind, $weightKg, $reason);
        if (! $entry->isOpen()) {
            throw new BusinessRuleViolation('This vehicle\'s visit is closed.');
        }
        if (self::current($entry, $kind) !== null) {
            throw new BusinessRuleViolation('The '.self::label($entry, $kind).' of this vehicle is already recorded.');
        }
        $evidence = $this->evidence($entry, $kind, $reason, $evidenceBytes);
        $this->gate->noteKnownWeight($entry, ['kind' => $kind, 'weight_kg' => Weight::kg(trim($weightKg))->kg, 'reason' => trim($reason),
            'evidence_attachment_id' => $evidence?->id, 'evidence_sha256' => $evidence?->file->sha256]);

        return self::current($entry->refresh(), $kind);   // null unless it was its turn after all
    }

    /** The rules every typed weight follows. */
    private function check(GateEntry $entry, string $kind, string $weightKg, string $reason): void
    {
        $me = $this->actor->account();
        if (! ManualWeighing::enabled($entry->entity_id)) {
            throw new BusinessRuleViolation('Weights cannot be typed by hand in this mill: they come from the weighbridge.');
        }
        if (! ManualWeighing::allowed($me instanceof LoginAccount ? $me : null)) {
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
        if ($otherWeight !== null && $otherWeight->source === Weighment::SOURCE_MANUAL && ! ManualWeighing::bothAllowed($entry->entity_id)) {
            throw new BusinessRuleViolation('The '.self::label($entry, $other).' was typed by hand, so this one must come from the weighbridge.');
        }

        // Only one of the two may be typed (unless the Owner allows both): also counting one noted for later.
        $otherNoted = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'known_weight_noted')->get()
            ->contains(fn (GateEvent $e) => ($e->data['kind'] ?? null) === $other);
        if ($otherNoted && $otherWeight === null && ! ManualWeighing::bothAllowed($entry->entity_id)) {
            throw new BusinessRuleViolation('The '.self::label($entry, $other).' was typed by hand, so this one must come from the weighbridge.');
        }
    }

    private function evidence(GateEntry $entry, string $kind, string $reason, ?string $evidenceBytes): ?Attachment
    {
        if ($evidenceBytes === null || $evidenceBytes === '') {
            return null;
        }

        return $this->attachments->attach($entry, 'weighbridge_slip', $evidenceBytes, 'Evidence for the typed '.self::label($entry, $kind).': '.trim($reason));
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
