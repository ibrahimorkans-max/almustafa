<?php

declare(strict_types=1);

namespace App\Support\Values;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A weight held in the base unit, kilograms, with 3 decimals (gram precision), as a decimal string.
 * All arithmetic uses bcmath — never floats. Display conversion to maund (40 kg, the trade unit in Pakistan), quintal (100 kg) and tonne (1000 kg).
 */
final readonly class Weight implements JsonSerializable, Stringable
{
    public const SCALE = 3;

    /** kilograms per unit */
    public const UNITS = ['kg' => '1', 'maund' => '40', 'quintal' => '100', 'ton' => '1000'];

    private function __construct(public string $kg) {}

    public static function kg(string|int $kg): self
    {
        return new self(self::normalize((string) $kg));
    }

    public static function from(string|int $amount, string $unit): self
    {
        $factor = self::UNITS[$unit] ?? throw new InvalidArgumentException("Unknown weight unit {$unit}.");

        return new self(self::normalize(bcmul(self::normalize((string) $amount), $factor, self::SCALE)));
    }

    public static function zero(): self
    {
        return new self('0.000');
    }

    public function in(string $unit, int $scale = 3): string
    {
        $factor = self::UNITS[$unit] ?? throw new InvalidArgumentException("Unknown weight unit {$unit}.");

        return self::round(bcdiv($this->kg, $factor, $scale + 1), $scale);
    }

    public function plus(self $other): self
    {
        return new self(bcadd($this->kg, $other->kg, self::SCALE));
    }

    public function minus(self $other): self
    {
        return new self(bcsub($this->kg, $other->kg, self::SCALE));
    }

    public function compare(self $other): int
    {
        return bccomp($this->kg, $other->kg, self::SCALE);
    }

    public function isNegative(): bool
    {
        return bccomp($this->kg, '0', self::SCALE) < 0;
    }

    public function __toString(): string
    {
        return $this->kg;
    }

    public function jsonSerialize(): string
    {
        return $this->kg;
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^-?\d{1,12}(\.\d{1,3})?$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid weight '{$value}' (max 3 decimals, no exponent).");
        }

        return bcadd($value, '0', self::SCALE);
    }

    /** Half-up rounding for display conversions. */
    private static function round(string $value, int $scale): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($value, '-')
            ? bcsub($value, $offset, $scale)
            : bcadd($value, $offset, $scale);
    }
}
