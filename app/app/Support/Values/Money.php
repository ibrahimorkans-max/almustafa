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

    /** Pakistani style, lakh grouping: Rs 12,34,567.89 */
    public function format(): string
    {
        return ($this->paise < 0 ? '-' : '').'Rs '.$this->grouped();
    }

    /** 12,34,567.89 without the currency (for table columns headed "Rs"). */
    public function grouped(): string
    {
        [$whole, $fraction] = explode('.', ltrim($this->toDecimal(), '-'));

        return self::lakhs($whole).'.'.$fraction;
    }

    /** Whole rupees in lakh grouping: "125000" → "1,25,000", "12345678" → "1,23,45,678". */
    public static function lakhs(string $digits): string
    {
        if (strlen($digits) <= 3) {
            return $digits;
        }
        $head = substr($digits, 0, -3);

        return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head).','.substr($digits, -3);
    }

    /** Rupees (not paise) as shown on screens: Rs 1,25,000.00 — for figures already in rupees. */
    public static function rupeesText(float|int|string $rupees, int $decimals = 2): string
    {
        $value = round((float) $rupees, $decimals);
        $sign = $value < 0 ? '-' : '';
        $fixed = number_format(abs($value), $decimals, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed), 2, '');

        return $sign.'Rs '.self::lakhs($whole).($decimals > 0 ? '.'.$fraction : '');
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
