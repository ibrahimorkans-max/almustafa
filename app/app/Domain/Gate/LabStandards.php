<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Models\Gate\GateEntry;
use App\Models\Gate\LabParameter;
use App\Models\Gate\LabTest;
use Illuminate\Support\Collection;

/**
 * The lab tests a lot is checked against, each with a standard limit and a grace limit (in basis
 * points: 14.00 % = 1400), and how a result reads against them:
 *   ok     within the standard limit           → waits for the lab supervisor's decision
 *   grace  above standard, within grace        → waits for the decision, with a grace warning
 *   over   above the grace limit               → rejected automatically, with the reason
 * Limits are set per mill (Gate & weighbridge → Lab tests & limits), optionally per product; a
 * product's own limit for a test replaces the general one. The figures below are only starting
 * values for a new mill and must be set to the mill's own standards.
 */
final class LabStandards
{
    /** code => [English, Urdu, standard %, grace %] */
    public const DEFAULTS = [
        'moisture' => ['Moisture', 'نمی', 14.0, 16.0],
        'green_grain' => ['Green grain', 'گرین گراؤ', 3.0, 5.0],
        'shell' => ['Shell', 'چھلکا', 2.0, 3.0],
        'shewad_green' => ['Shewad green', 'شیواڈ گرین', 3.0, 5.0],
        'broken' => ['Broken', 'ٹوٹا ہوا دانہ', 5.0, 8.0],
        'red_rice' => ['Red rice', 'سرخ چاول', 2.0, 4.0],
        'dandly' => ['Dandly', 'ڈنڈلی', 1.0, 2.0],
    ];

    public const OK = 'ok';

    public const GRACE = 'grace';

    public const OVER = 'over';

    /** @return Collection<string, LabParameter> active tests for a product, keyed by code, in order */
    public function parametersFor(?string $productId): Collection
    {
        return LabParameter::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('product_id')->when($productId !== null, fn ($q) => $q->orWhere('product_id', $productId)))
            ->orderBy('sort')->orderBy('code')->get()
            // A product's own limit replaces the general one for the same test.
            ->sortBy(fn (LabParameter $p) => $p->product_id === null ? 0 : 1)
            ->keyBy('code')
            ->sortBy('sort');
    }

    /**
     * Each measured result against its limits.
     *
     * @param  array<string, int>  $results  code => basis points
     * @return list<array{code: string, name: string, name_ur: string, unit: string, value_bp: int, standard_bp: int, grace_bp: int, verdict: string}>
     */
    public function evaluate(array $results, ?string $productId): array
    {
        $out = [];
        foreach ($this->parametersFor($productId) as $code => $p) {
            if (! array_key_exists($code, $results)) {
                continue;
            }
            $value = (int) $results[$code];
            $out[] = [
                'code' => $code, 'name' => $p->name_en, 'name_ur' => $p->name_ur, 'unit' => $p->unit,
                'value_bp' => $value, 'standard_bp' => $p->standard_bp, 'grace_bp' => $p->grace_bp,
                'verdict' => match (true) {
                    $value > $p->grace_bp => self::OVER,
                    $value > $p->standard_bp => self::GRACE,
                    default => self::OK,
                },
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the results of the latest lab test of a vehicle above their grace limit */
    public function overGrace(GateEntry $entry): array
    {
        $test = LabTest::query()->where('gate_entry_id', $entry->id)->orderByDesc('tested_at')->first();

        return array_values(array_filter((array) ($test->evaluation ?? []), fn ($r) => is_array($r) && ($r['verdict'] ?? null) === self::OVER));
    }

    /**
     * "Moisture (نمی) 18.5 % — above the grace limit 16 %"
     *
     * @param  array<string, mixed>  $r  one row of evaluate()
     */
    public static function describe(array $r): string
    {
        $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');

        return $r['name'].' ('.$r['name_ur'].') '.$pct((int) $r['value_bp']).' '.$r['unit']
            .match ($r['verdict']) {
                self::OVER => ' — above the grace limit '.$pct((int) $r['grace_bp']).' '.$r['unit'],
                self::GRACE => ' — above standard '.$pct((int) $r['standard_bp']).', within grace '.$pct((int) $r['grace_bp']).' '.$r['unit'],
                default => ' — within standard '.$pct((int) $r['standard_bp']).' '.$r['unit'],
            };
    }
}
