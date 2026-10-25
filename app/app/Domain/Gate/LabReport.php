<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\LabTest;

/**
 * A vehicle's lab report as printed and shown: each lab test's result against its standard and grace
 * limit, the decision, and — when the lot was rejected — why (the tests over their grace limit, or the
 * lab's note); a lot taken in after negotiation shows both the rejection and the reason it was taken.
 */
final class LabReport
{
    /**
     * @return array{test: LabTest|null, rows: list<array<string, mixed>>, decision: string, rejected_reason: string|null,
     *               accepted_reason: string|null, after_negotiation: bool, corrections: int}
     */
    public static function for(GateEntry $entry): array
    {
        $tests = LabTest::query()->where('gate_entry_id', $entry->id)->latestFirst()->get();
        $test = $tests->first();
        $decisions = GateEvent::query()->where('gate_entry_id', $entry->id)->whereIn('event', ['lab_accepted', 'lab_rejected', 'lab_corrected'])
            ->chronological()->get();
        // A corrected result clears the decisions before it.
        $lastCorrection = $decisions->where('event', 'lab_corrected')->keys()->last();
        $current = $lastCorrection === null ? $decisions : $decisions->slice($lastCorrection + 1);
        $rejected = $current->where('event', 'lab_rejected')->last();
        $accepted = $current->where('event', 'lab_accepted')->last();
        $afterNegotiation = (bool) ($accepted->data['after_negotiation'] ?? false);

        $decision = match (true) {
            $accepted !== null => 'accepted',
            $rejected !== null => 'rejected',
            default => 'pending',
        };

        return [
            'test' => $test,
            'rows' => array_values((array) ($test->evaluation ?? [])),
            'decision' => $decision,
            'rejected_reason' => $rejected !== null ? (string) ($rejected->data['note'] ?? 'Rejected by the lab') : null,
            'accepted_reason' => $accepted !== null ? ($accepted->data['note'] ?? null) : null,
            'after_negotiation' => $afterNegotiation,
            'corrections' => max(0, $tests->count() - 1),
        ];
    }

    /** @param  array{decision: string, after_negotiation: bool}  $report */
    public static function decisionLabel(array $report): string
    {
        return match ($report['decision']) {
            'accepted' => $report['after_negotiation'] ? 'Accepted after negotiation (it had been rejected)' : 'Accepted',
            'rejected' => 'REJECTED',
            default => 'Waiting for the lab decision',
        };
    }

    /** "12.5" from basis points. */
    public static function pct(?int $bp): string
    {
        return $bp === null ? '—' : rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
    }
}
