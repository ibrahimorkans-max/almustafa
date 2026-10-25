<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

/**
 * The layout of every message to a party, in WhatsApp's own formatting (*bold*):
 *
 *     *Al Mustafa Rice Mills*
 *     *Payment made to you*
 *     Amount: *Rs 3,68,053.00*
 *     Entry: PAY/2026-27/0001 · 22 Oct 2026
 *     Balance: *Your account is settled*
 *     Thank you for working with us.
 *
 * The stored text is this; a notification or an SMS gets it without the bold marks, and the WhatsApp
 * Business template (which already carries the mill's name and the thank-you) gets the middle lines
 * joined on one line, as a template variable may not hold line breaks.
 */
final class MessageText
{
    public const CLOSING = 'Thank you for working with us.';

    /**
     * @param  list<array{0: string, 1: string, 2?: bool}|string>  $lines  [label, value, bold] or a plain sentence
     */
    public static function make(string $mill, string $title, array $lines): string
    {
        $out = ['*'.self::clean($mill).'*', '*'.self::clean($title).'*'];
        foreach ($lines as $line) {
            if (is_string($line)) {
                $out[] = self::clean($line);

                continue;
            }
            [$label, $value] = $line;
            $value = self::clean($value);
            $out[] = $label.': '.(($line[2] ?? false) && $value !== '' ? '*'.$value.'*' : $value);
        }
        $out[] = self::CLOSING;

        return implode("\n", $out);
    }

    /** For a notification or an SMS: the same lines without the bold marks. */
    public static function plain(string $text): string
    {
        return str_replace('*', '', $text);
    }

    /** For the WhatsApp Business template: the lines between the mill's name and the thank-you, on one line. */
    public static function forTemplate(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $l) => $l !== ''));
        if (count($lines) > 2 && str_starts_with($lines[0], '*') && str_ends_with($lines[0], '*')) {
            array_shift($lines);   // the mill's name: the template has it
        }
        if ($lines !== [] && end($lines) === self::CLOSING) {
            array_pop($lines);   // the thank-you: the template has it
        }

        return implode(' · ', $lines);
    }

    /** No stray bold marks or line breaks inside a value. */
    private static function clean(string $value): string
    {
        return trim(str_replace(['*', "\r", "\n"], ['', ' ', ' '], $value));
    }
}
