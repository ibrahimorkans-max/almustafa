<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The languages of the system: which ones exist, which one this request uses, and translating option lists.
 */
final class Locales
{
    public const SESSION = 'locale';

    /** @return array<string, array{name: string, native: string, dir: string}> */
    public static function all(): array
    {
        return (array) config('languages.available');
    }

    public static function isValid(?string $code): bool
    {
        return is_string($code) && isset(self::all()[$code]);
    }

    public static function current(): string
    {
        return app()->getLocale();
    }

    public static function isRtl(?string $code = null): bool
    {
        return (self::all()[$code ?? self::current()]['dir'] ?? 'ltr') === 'rtl';
    }

    /**
     * Every label of an option list in the chosen language (keys untouched; grouped lists too).
     *
     * @template T
     *
     * @param  T  $options
     * @return T
     */
    public static function options(mixed $options): mixed
    {
        if ($options instanceof \Illuminate\Support\Collection) {
            $options = $options->all();
        }
        if (! is_array($options)) {
            return $options;
        }
        $out = [];
        foreach ($options as $key => $label) {
            if (is_array($label)) {                       // a group: its heading is shown, its key is not a value
                $out[is_string($key) ? self::word($key) : $key] = self::options($label);
            } else {
                $out[$key] = is_string($label) ? self::word($label) : $label;
            }
        }

        return $out;
    }

    /** One text in the chosen language; English when it has no translation yet. */
    public static function word(string $text): string
    {
        $line = __($text);

        return is_string($line) ? $line : $text;
    }
}
