<?php

declare(strict_types=1);

use App\Domain\Documents\PdfRenderer;
use App\Support\Values\AmountInWords;
use App\Support\Values\Money;
use App\Support\Values\PakistanIds;
use App\Support\Values\Weight;

describe('Weight', function () {
    it('stores kilograms with gram precision and converts for display', function () {
        $w = Weight::from('12.5', 'quintal');

        expect($w->kg)->toBe('1250.000')
            ->and($w->in('ton'))->toBe('1.250')
            ->and(Weight::kg('1234.5678 ')->kg ?? null)->toBeNull();
    })->throws(InvalidArgumentException::class);

    it('uses the 40 kg maund as the trade unit', function () {
        expect(Weight::from('12.5', 'maund')->kg)->toBe('500.000')
            ->and(Weight::kg('20240')->in('maund'))->toBe('506.000')
            ->and(Weight::kg('999.999')->in('maund', 2))->toBe('25.00')
            ->and(PdfRenderer::maundKg('19720.600'))->toBe('493 maund 0.600 kg')
            ->and(PdfRenderer::maundKg('39.990'))->toBe('0 maund 39.990 kg');
    });

    it('adds and subtracts exactly (no float drift)', function () {
        $gross = Weight::kg('25480.120');
        $tare = Weight::kg('10230.070');

        expect($gross->minus($tare)->kg)->toBe('15250.050')
            ->and(Weight::kg('0.1')->plus(Weight::kg('0.2'))->kg)->toBe('0.300')
            ->and($tare->minus($gross)->isNegative())->toBeTrue()
            ->and(Weight::kg('999.999')->in('quintal', 2))->toBe('10.00');
    });

    it('rejects unknown units and exponents', function () {
        expect(fn () => Weight::from('1', 'seer'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Weight::kg('1e3'))->toThrow(InvalidArgumentException::class);
    });
});

describe('Money', function () {
    it('parses rupees to integer paisa without floats', function () {
        expect(Money::rupees('1,234.5')->paise)->toBe(123450)
            ->and(Money::rupees('Rs 0.07')->paise)->toBe(7)
            ->and(Money::rupees('PKR 2,500')->paise)->toBe(250000)
            ->and(Money::rupees('-12')->paise)->toBe(-1200)
            ->and(Money::paise(123456789)->toDecimal())->toBe('1234567.89');
    });

    it('formats with Pakistani (thousands) grouping', function () {
        expect(Money::paise(123456789)->format())->toBe('Rs 12,34,567.89')
            ->and(Money::paise(99900)->format())->toBe('Rs 999.00')
            ->and(Money::paise(-1000000000)->format())->toBe('-Rs 1,00,00,000.00')
            ->and(Money::paise(5)->grouped())->toBe('0.05');
    });

    it('rejects more than two decimals', function () {
        Money::rupees('1.234');
    })->throws(InvalidArgumentException::class);

    it('writes amounts in words as on Pakistani invoices', function () {
        expect(AmountInWords::rupees(125_050_050))->toBe('Rupees One Million Two Hundred Fifty Thousand Five Hundred and Fifty Paisa Only')
            ->and(AmountInWords::rupees(848_125_000))->toBe('Rupees Eight Million Four Hundred Eighty-One Thousand Two Hundred Fifty Only')
            ->and(AmountInWords::rupees(100))->toBe('Rupees One Only')
            ->and(AmountInWords::rupees(0))->toBe('Rupees Zero Only');
    });
});

describe('Pakistani identifiers', function () {
    it('validates and formats CNIC, NTN and STRN', function () {
        expect(PakistanIds::isValidCnic('3520212345671'))->toBeTrue()
            ->and(PakistanIds::isValidCnic('8520212345671'))->toBeFalse()   // no region 8
            ->and(PakistanIds::isValidCnic('352021234567'))->toBeFalse()
            ->and(PakistanIds::digits('35202-1234567-1'))->toBe('3520212345671')
            ->and(PakistanIds::formatCnic('3520212345671'))->toBe('35202-1234567-1')
            ->and(PakistanIds::isValidNtn('12345678'))->toBeTrue()
            ->and(PakistanIds::isValidNtn('3520212345671'))->toBeTrue()     // an individual's CNIC
            ->and(PakistanIds::isValidNtn('1234567'))->toBeFalse()
            ->and(PakistanIds::formatNtn('12345678'))->toBe('1234567-8')
            ->and(PakistanIds::isValidStrn('3277876543210'))->toBeTrue()
            ->and(PakistanIds::isValidStrn('327787654321'))->toBeFalse();
    });

    it('checks IBAN check digits (ISO 13616 mod 97)', function () {
        expect(PakistanIds::isValidIban('PK36SCBL0000001123456702'))->toBeTrue()
            ->and(PakistanIds::isValidIban('PK37SCBL0000001123456702'))->toBeFalse()
            ->and(PakistanIds::isValidIban((string) PakistanIds::normalizeIban('pk36 scbl 0000 0011 2345 6702')))->toBeTrue()
            ->and(PakistanIds::isValidIban('GB29NWBK60161331926819'))->toBeFalse()
            ->and(PakistanIds::ibanFor('MEZN', '0012345678901234'))->toStartWith('PK')
            ->and(PakistanIds::isValidIban(PakistanIds::ibanFor('MEZN', '0012345678901234')))->toBeTrue()
            ->and(PakistanIds::bankCodeOfIban('PK36SCBL0000001123456702'))->toBe('SCBL')
            ->and(PakistanIds::formatIban('PK36SCBL0000001123456702'))->toBe('PK36 SCBL 0000 0011 2345 6702');
    });

    it('normalises and formats Pakistani vehicle numbers', function (string $input, ?string $normalized, ?string $printed) {
        $n = PakistanIds::normalizeVehicleNumber($input);

        expect(PakistanIds::isValidVehicleNumber($n) ? $n : null)->toBe($normalized);
        if ($normalized !== null) {
            expect(PakistanIds::formatVehicleNumber($normalized))->toBe($printed);
        }
    })->with([
        ['les-21-1234', 'LES211234', 'LES-21-1234'],
        ['MNA 1234', 'MNA1234', 'MNA-1234'],
        ['BKX-123', 'BKX123', 'BKX-123'],
        ['NOT A PLATE', null, null],
        ['1234', null, null],
    ]);

    it('normalises Pakistani mobile numbers', function () {
        expect(PakistanIds::normalizeMobile('0300-6834588'))->toBe('+923006834588')
            ->and(PakistanIds::normalizeMobile('+92 300 6834588'))->toBe('+923006834588')
            ->and(PakistanIds::normalizeMobile('0092 300 6834588'))->toBe('+923006834588')
            ->and(PakistanIds::formatMobile('+923006834588'))->toBe('0300-6834588')
            ->and(PakistanIds::normalizeMobile('042-35761234'))->toBeNull()   // landline
            ->and(PakistanIds::normalizeMobile('12345'))->toBeNull();
    });
});
