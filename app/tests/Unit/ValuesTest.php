<?php

declare(strict_types=1);

use App\Support\Values\IndianIds;
use App\Support\Values\Money;
use App\Support\Values\Weight;

describe('Weight', function () {
    it('stores kilograms with gram precision and converts for display', function () {
        $w = Weight::from('12.5', 'quintal');

        expect($w->kg)->toBe('1250.000')
            ->and($w->in('ton'))->toBe('1.250')
            ->and(Weight::kg('1234.5678 ')->kg ?? null)->toBeNull();
    })->throws(InvalidArgumentException::class);

    it('adds and subtracts exactly (no float drift)', function () {
        $gross = Weight::kg('25480.120');
        $tare = Weight::kg('10230.070');

        expect($gross->minus($tare)->kg)->toBe('15250.050')
            ->and(Weight::kg('0.1')->plus(Weight::kg('0.2'))->kg)->toBe('0.300')
            ->and($tare->minus($gross)->isNegative())->toBeTrue()
            ->and(Weight::kg('999.999')->in('quintal', 2))->toBe('10.00');
    });

    it('rejects unknown units and exponents', function () {
        expect(fn () => Weight::from('1', 'maund'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Weight::kg('1e3'))->toThrow(InvalidArgumentException::class);
    });
});

describe('Money', function () {
    it('parses rupees to integer paise without floats', function () {
        expect(Money::rupees('1,234.5')->paise)->toBe(123450)
            ->and(Money::rupees('0.07')->paise)->toBe(7)
            ->and(Money::rupees('-12')->paise)->toBe(-1200)
            ->and(Money::paise(123456789)->toDecimal())->toBe('1234567.89');
    });

    it('formats with Indian digit grouping', function () {
        expect(Money::paise(123456789)->format())->toBe('₹12,34,567.89')
            ->and(Money::paise(99900)->format())->toBe('₹999.00')
            ->and(Money::paise(-1000000000)->format())->toBe('-₹1,00,00,000.00');
    });

    it('rejects more than two decimals', function () {
        Money::rupees('1.234');
    })->throws(InvalidArgumentException::class);
});

describe('Indian identifiers', function () {
    it('validates the GSTIN check character', function () {
        expect(IndianIds::isValidGstin('27AAPFU0939F1ZV'))->toBeTrue()
            ->and(IndianIds::isValidGstin('27AAPFU0939F1ZW'))->toBeFalse()   // wrong check char
            ->and(IndianIds::isValidGstin('99AAPFU0939F1ZV'))->toBeFalse()   // state 99 + wrong check
            ->and(IndianIds::isValidGstin('27AAPFU0939F1Z'))->toBeFalse()
            ->and(IndianIds::panFromGstin('27AAPFU0939F1ZV'))->toBe('AAPFU0939F');

        $first14 = '06ABCDE1234F1Z';
        expect(IndianIds::isValidGstin($first14.IndianIds::gstinCheckChar($first14)))->toBeTrue();
    });

    it('validates PAN and IFSC', function () {
        expect(IndianIds::isValidPan('AAPFU0939F'))->toBeTrue()
            ->and(IndianIds::isValidPan('AAPXU0939F'))->toBeFalse() // 4th char must be a holder type
            ->and(IndianIds::isValidIfsc('SBIN0001234'))->toBeTrue()
            ->and(IndianIds::isValidIfsc('SBIN1001234'))->toBeFalse();
    });

    it('normalises vehicle numbers and accepts state and BH series', function (string $input, ?string $normalized) {
        $n = IndianIds::normalizeVehicleNumber($input);

        expect(IndianIds::isValidVehicleNumber($n) ? $n : null)->toBe($normalized);
    })->with([
        ['hr 05 ab 1234', 'HR05AB1234'],
        ['DL-1C-AB-1234', 'DL1CAB1234'],
        ['PB10X9', 'PB10X9'],
        ['22 BH 1234 AA', '22BH1234AA'],
        ['NOT A PLATE', null],
        ['1234', null],
    ]);

    it('normalises Indian mobile numbers', function () {
        expect(IndianIds::normalizeMobile('098765 43210'))->toBe('+919876543210')
            ->and(IndianIds::normalizeMobile('+91-98765-43210'))->toBe('+919876543210')
            ->and(IndianIds::normalizeMobile('12345'))->toBeNull()
            ->and(IndianIds::normalizeMobile('5876543210'))->toBeNull();
    });
});
