<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Models\Gate\Weighment;
use App\Support\Values\Weight;

/**
 * Repeat-tare check: an empty truck weighs about the same every visit. A tare far from the
 * vehicle's recent median (beyond max(tolerance kg, tolerance % of median)) is flagged —
 * a classic sign of extra water, fuel or a person in the cab during the tare weighment.
 */
final class TareCheck
{
    /** @return array{median_kg: string, difference_kg: string, tolerance_kg: string, samples: int}|null */
    public function check(string $registrationNo, Weight $tare, string $excludeGateEntryId): ?array
    {
        $history = Weighment::query()
            ->join('gate_entries', 'gate_entries.id', '=', 'weighments.gate_entry_id')
            ->where('gate_entries.registration_no', $registrationNo)
            ->where('weighments.gate_entry_id', '!=', $excludeGateEntryId)
            ->where('weighments.kind', Weighment::TARE)
            // Empty weights carrying rejected bags are not the vehicle's own weight.
            ->where(fn ($q) => $q->where('gate_entries.rejected_off_vehicle', true)
                ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('gate_entries.rejected_bags')->orWhere('gate_entries.rejected_bags', 0))->whereNull('gate_entries.rejected_kg')))
            ->orderByDesc('weighments.captured_at')
            ->limit((int) config('ricemill.gate.tare_history'))
            ->pluck('weighments.weight_kg')
            ->map(fn ($kg) => Weight::kg((string) $kg))
            ->all();

        if (count($history) < 2) {
            return null; // not enough history to judge
        }

        usort($history, fn (Weight $a, Weight $b) => $a->compare($b));
        $mid = intdiv(count($history), 2);
        $median = count($history) % 2 === 1
            ? $history[$mid]
            : Weight::kg(bcdiv($history[$mid - 1]->plus($history[$mid])->kg, '2', 3));

        $difference = $tare->minus($median);
        $absolute = $difference->isNegative() ? Weight::zero()->minus($difference) : $difference;

        $byPercent = Weight::kg(bcdiv(bcmul($median->kg, (string) config('ricemill.gate.tare_tolerance_bp'), 3), '10000', 3));
        $byKg = Weight::kg((string) config('ricemill.gate.tare_tolerance_kg'));
        $tolerance = $byPercent->compare($byKg) > 0 ? $byPercent : $byKg;

        if ($absolute->compare($tolerance) <= 0) {
            return null;
        }

        return [
            'median_kg' => $median->kg,
            'difference_kg' => $difference->kg,
            'tolerance_kg' => $tolerance->kg,
            'samples' => count($history),
        ];
    }
}
