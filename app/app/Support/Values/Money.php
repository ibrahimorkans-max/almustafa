<?php

declare(strict_types=1);

namespace App\Support\Values;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Pakistani rupees held as integer paisa (1/100 rupee; columns keep the name "paise"). Parsing and formatting never go through floats.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(public int $paise) {}

    public static function paise(int $paise): self
    {
        return new self($paise);
    }

    /** "1234.5" / "1,234.50" / "-12" → paise */
    public static function rupees(string|int $rupees): self
    {
        $value = str_replace([',', ' ', 'Rs.', 'Rs', 'PKR', '₨'], '', trim((string) $rupees));
        if (preg_match('/^(-?)(\d{1,15})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("Invalid amount '{$rupees}' (max 2 decimals).");
        }

        $paise = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return new self($m[1] === '-' ? -$paise : $paise);
    }

    public function plus(self $other): self
    {
        return new self($this->paise + $other->paise);
    }

    public function minus(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    /** "1234.50" */
    public function toDecimal(): string
    {
        $abs = abs($this->paise);

        return ($this->paise < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Pakistani style, grouped in thousands: Rs 1,234,567.89 */
    public function format(): string
    {
        return ($this->paise < 0 ? '-' : '').'Rs '.$this->grouped();
    }

    /** 1,234,567.89 without the currency (for table columns headed "Rs"). */
    public function grouped(): string
    {
        [$whole, $fraction] = explode('.', ltrim($this->toDecimal(), '-'));

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    public function jsonSerialize(): int
    {
        return $this->paise;
    }
}
