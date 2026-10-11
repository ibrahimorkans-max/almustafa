<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentNumbers;
use App\Domain\Gate\DeductionCalculator;
use App\Models\Gate\DeductionRule;
use Carbon\CarbonImmutable;

function rule(array $attributes): DeductionRule
{
    return (new DeductionRule)->forceFill($attributes + ['status' => 'active', 'reject_above_bp' => null, 'free_limit_bp' => 0]);
}

describe('deductions', function () {
    it('deducts excess moisture as a share of net weight', function () {
        // 18.5 % moisture, 17 % free → 1.5 % of 20,000 kg = 300 kg
        $r = (new DeductionCalculator)->calculate('20000.000', null, ['moisture' => 1850], [
            rule(['parameter' => 'moisture', 'method' => 'excess_weight', 'free_limit_bp' => 1700, 'factor' => 10000]),
        ]);

        expect($r['deduction_kg'])->toBe('300.000')->and($r['reject'])->toBeFalse();
    });

    it('applies a half-weight factor, rate cuts per full point and bag weight', function () {
        $r = (new DeductionCalculator)->calculate('20000.000', 400, ['moisture' => 1850, 'foreign_matter' => 470], [
            rule(['parameter' => 'moisture', 'method' => 'excess_weight', 'free_limit_bp' => 1700, 'factor' => 5000]),  // 0.75 %
            rule(['parameter' => 'foreign_matter', 'method' => 'rate_cut', 'free_limit_bp' => 200, 'factor' => 1000]),   // 2 full points × Rs 10
            rule(['parameter' => 'bags', 'method' => 'per_bag', 'factor' => 600]),                                          // 400 × 0.6 kg
        ]);

        expect($r['deduction_kg'])->toBe('390.000') // 150 + 240
            ->and($r['rate_cut_paise_per_maund'])->toBe(2000)
            ->and($r['lines'])->toHaveCount(3);
    });

    it('ignores values within the free limit and flags reject limits', function () {
        $r = (new DeductionCalculator)->calculate('10000.000', null, ['moisture' => 2300], [
            rule(['parameter' => 'moisture', 'method' => 'excess_weight', 'free_limit_bp' => 1700, 'factor' => 10000, 'reject_above_bp' => 2200]),
        ]);
        $ok = (new DeductionCalculator)->calculate('10000.000', null, ['moisture' => 1650], [
            rule(['parameter' => 'moisture', 'method' => 'excess_weight', 'free_limit_bp' => 1700, 'factor' => 10000]),
        ]);

        expect($r['reject'])->toBeTrue()->and($ok['deduction_kg'])->toBe('0.000');
    });

    it('never deducts more than the net weight', function () {
        $r = (new DeductionCalculator)->calculate('100.000', 1000, [], [rule(['parameter' => 'bags', 'method' => 'per_bag', 'factor' => 600])]);

        expect($r['deduction_kg'])->toBe('100.000');
    });
});

it('uses the Pakistani tax year (July to June, PKT)', function () {
    expect(DocumentNumbers::financialYear(CarbonImmutable::parse('2026-07-01 00:00', 'Asia/Karachi')))->toBe('2026-27')
        ->and(DocumentNumbers::financialYear(CarbonImmutable::parse('2027-06-30 23:59', 'Asia/Karachi')))->toBe('2026-27')
        ->and(DocumentNumbers::financialYear(CarbonImmutable::parse('2026-06-30 12:00', 'Asia/Karachi')))->toBe('2025-26')
        // 30 Jun 19:00 UTC is already 1 Jul in Pakistan
        ->and(DocumentNumbers::financialYear(CarbonImmutable::parse('2027-06-30 19:00', 'UTC')))->toBe('2027-28')
        ->and(DocumentNumbers::financialYear(CarbonImmutable::parse('2099-12-01')))->toBe('2099-00');
});
