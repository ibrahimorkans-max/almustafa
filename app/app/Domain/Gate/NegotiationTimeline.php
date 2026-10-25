<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\GatePriceVersion;
use App\Support\Values\Money;
use Carbon\CarbonInterface;

/**
 * Everything negotiated about one vehicle, in time order: the lab's results, corrections and decisions,
 * each price proposed and how it was decided, each Caat proposal and its approval, a rejected lot taken
 * in, a settlement reopened — with when, who and why. Nothing here is ever changed or removed: each step
 * is its own record.
 */
final class NegotiationTimeline
{
    /** @return list<array{at: CarbonInterface, who: string, what: string, detail: string, tone: string}> */
    public static function for(GateEntry $entry): array
    {
        $rows = [];
        $rupees = fn (?int $paise) => $paise === null ? '—' : 'Rs '.Money::paise($paise)->grouped();
        $events = GateEvent::query()->with('actor')->where('gate_entry_id', $entry->id)
            ->whereIn('event', ['lab_accepted', 'lab_rejected', 'lab_corrected', 'gross_weighed', 'tare_weighed', 'quarantined',
                'settlement_proposed', 'settlement_approved', 'settlement_reopened', 'slip_issued'])
            ->chronological()->get();
        foreach ($events as $e) {
            $d = (array) $e->data;
            [$what, $detail, $tone] = match ($e->event) {
                'lab_rejected' => ['Lab rejected', (string) ($d['note'] ?? ''), 'danger'],
                'lab_accepted' => ($d['after_negotiation'] ?? false)
                    ? ['Rejected lot taken in', trim(($d['note'] ?? '').' · price '.$rupees($d['price_paise_per_maund'] ?? null).' / maund', ' ·'), 'warning']
                    : ['Lab accepted', (string) ($d['note'] ?? ''), 'success'],
                'lab_corrected' => ['Lab result corrected', (string) ($d['reason'] ?? '').(($d['cleared_decision'] ?? false) ? ' (the earlier decision no longer counts)' : ''), 'warning'],
                'gross_weighed' => ['Loaded weight', number_format((float) ($d['weight_kg'] ?? 0), 0).' kg', 'gray'],
                'tare_weighed' => ['Empty weight', number_format((float) ($d['weight_kg'] ?? 0), 0).' kg', 'gray'],
                'quarantined' => ['Held apart (quarantine)', trim(($d['location'] ?? '').' '.($d['note'] ?? '')), 'warning'],
                'settlement_proposed' => ['Caat proposed', 'Waste Caat '.($d['waste_caat_kg'] ?? '0').' kg + Caat per Maund '.($d['per_maund_rate_kg'] ?? '0')
                    .' kg × maunds = '.($d['total_caat_kg'] ?? '0').' kg → payable '.($d['payable_kg'] ?? '—').' kg at '
                    .$rupees($d['price_paise_per_maund'] ?? null).' / maund'.(! empty($d['note']) ? ' — '.$d['note'] : ''), 'info'],
                'settlement_approved' => ['Caat and price approved', (string) ($d['note'] ?? ''), 'success'],
                'settlement_reopened' => ['Settlement reopened', (string) ($d['reason'] ?? ''), 'warning'],
                'slip_issued' => ['Slip issued', 'Payable '.($d['payable_kg'] ?? '—').' kg', 'success'],
                default => [$e->event, '', 'gray'],
            };
            $rows[] = ['at' => $e->occurred_at, 'who' => $e->actor->username ?? 'device / system', 'what' => $what, 'detail' => trim($detail), 'tone' => $tone];
        }

        foreach (GatePriceVersion::query()->with(['proposer', 'decider'])->where('gate_entry_id', $entry->id)->orderBy('version')->get() as $p) {
            $rows[] = ['at' => $p->proposed_at, 'who' => $p->proposer->username ?? '—', 'what' => 'Price proposed (version '.$p->version.')',
                'detail' => $rupees($p->price_paise_per_maund).' / maund'.($p->reason ? ' — '.$p->reason : ''), 'tone' => 'info'];
            if ($p->decided_at !== null && in_array($p->status, [GatePriceVersion::ACCEPTED, GatePriceVersion::REJECTED], true)) {
                $rows[] = ['at' => $p->decided_at, 'who' => $p->decider->username ?? '—',
                    'what' => 'Price '.($p->status === GatePriceVersion::ACCEPTED ? 'accepted' : 'rejected').' (version '.$p->version.')',
                    'detail' => $rupees($p->price_paise_per_maund).' / maund'.($p->decision_note ? ' — '.$p->decision_note : ''),
                    'tone' => $p->status === GatePriceVersion::ACCEPTED ? 'success' : 'danger'];
            }
        }

        usort($rows, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $rows;
    }
}
