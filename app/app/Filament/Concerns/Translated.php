<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\Locales;

/** @internal One name in the chosen language (English when not translated yet). */
final class Translated
{
    public static function text(string $text): string
    {
        return Locales::word($text);
    }
}
