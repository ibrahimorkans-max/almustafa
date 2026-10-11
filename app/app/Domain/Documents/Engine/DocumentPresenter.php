<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

use App\Support\Values\AmountInWords;
use App\Support\Values\Money;
use App\Support\Values\UrduAmountInWords;
use Carbon\CarbonImmutable;
use Illuminate\Support\HtmlString;

/**
 * Turns the language-neutral content of a document into printed text for one language (English,
 * Urdu, or both) and digit style. Labels and pay heads come from lang/{en,ur}/documents.php; a label
 * with no Urdu text prints in English. Figures are formatted here and nowhere else, so every layout
 * and language shows exactly the same numbers.
 */
final class DocumentPresenter
{
    public const LANGUAGES = ['en' => 'English', 'ur' => 'Urdu', 'bilingual' => 'English + Urdu'];

    public function __construct(
        public readonly string $language = 'en',
        public readonly string $digits = 'western',
    ) {}

    public function rtl(): bool
    {
        return $this->language === 'ur';
    }

    public function title(string $key): HtmlString
    {
        return $this->both("titles.{$key}", $key);
    }

    public function titleEn(string $key): string
    {
        return $this->en("titles.{$key}", $key);
    }

    public function titleUr(string $key): string
    {
        return $this->ur("titles.{$key}") ?? '';
    }

    public function label(string $key): HtmlString
    {
        return $this->both("labels.{$key}", $key);
    }

    /** A pay head: its Urdu name when the code is known, else the name given on the record. */
    public function head(string $code, string $name): HtmlString
    {
        $ur = $this->ur("heads.{$code}");

        return $this->pair($name, $ur);
    }

    /**
     * A paragraph with values filled in (certificates, receipts). A value may be typed so that each
     * language gets its own form: ['m' => paise] amount, ['w' => paise] amount in words, ['ym' => 'Y-m'] month, ['d' => date].
     *
     * @param  array<string, mixed>  $vars
     */
    public function text(string $key, array $vars): HtmlString
    {
        $en = e(__("documents.text.{$key}", self::vars($vars, 'en'), 'en'));
        // Urdu: each value goes in through a placeholder, so English values (names, amounts) keep left-to-right order.
        $urVars = self::vars($vars, 'ur');
        $tokens = [];
        foreach ($urVars as $name => $value) {
            $tokens[$name] = "\u{E000}{$name}\u{E001}";
        }
        $urRaw = __("documents.text.{$key}", $tokens, 'ur');
        $ur = null;
        if ($urRaw !== "documents.text.{$key}") {
            $ur = e($this->num((string) $urRaw));
            foreach ($urVars as $name => $value) {
                $value = $this->num($value);
                $ur = str_replace("\u{E000}{$name}\u{E001}", preg_match('/\p{Arabic}/u', $value) === 1 ? e($value) : '<bdo dir="ltr">'.e($value).'</bdo>', $ur);
            }
        }

        return new HtmlString(match ($this->language) {
            'ur' => '<div class="ur" dir="rtl">'.($ur ?? $en).'</div>',
            'bilingual' => '<div>'.$en.'</div>'.($ur !== null ? '<div class="ur" dir="rtl">'.$ur.'</div>' : ''),
            default => '<div>'.$en.'</div>',
        });
    }

    /**
     * @param  array<string, mixed>  $vars
     * @return array<string, string>
     */
    public static function vars(array $vars, string $lang): array
    {
        return array_map(fn (mixed $v): string => match (true) {
            ! is_array($v) => (string) $v,
            array_key_exists('s', $v) => (string) ($v['masked'] ?? '—'),
            array_key_exists('m', $v) => Money::paise((int) $v['m'])->format(),
            array_key_exists('w', $v) => $lang === 'ur' ? UrduAmountInWords::rupees((int) $v['w']) : AmountInWords::rupees((int) $v['w']),
            array_key_exists('ym', $v) => CarbonImmutable::createFromFormat('!Y-m', (string) $v['ym'])->format('F Y'),
            array_key_exists('d', $v) => CarbonImmutable::parse((string) $v['d'])->format('d M Y'),
            default => '',
        }, $vars);
    }

    /** "1,234.50" (column headed Rs), in the chosen digits. */
    public function money(int $paise): string
    {
        return $this->num(($paise < 0 ? '-' : '').Money::paise(abs($paise))->grouped());
    }

    /** "Rs 1,234.50". */
    public function rupees(int $paise): string
    {
        return $this->num(Money::paise($paise)->format());
    }

    public function words(int $paise): HtmlString
    {
        $en = e(AmountInWords::rupees($paise));
        $ur = e(UrduAmountInWords::rupees($paise));

        return new HtmlString(match ($this->language) {
            'ur' => '<span class="ur">'.$ur.'</span>',
            'bilingual' => $en.'<br><span class="ur">'.$ur.'</span>',
            default => $en,
        });
    }

    public function date(?string $ymd): string
    {
        return $ymd === null || $ymd === '' ? '—' : $this->num(CarbonImmutable::parse($ymd)->format('d M Y'));
    }

    public function month(string $ym): string
    {
        return $this->num(CarbonImmutable::createFromFormat('!Y-m', $ym)->format('F Y'));
    }

    public function num(string $text): string
    {
        return $this->digits === 'urdu' ? UrduAmountInWords::digits($text) : $text;
    }

    /**
     * One cell of content: plain text, or a typed value.
     *   ['m' => paise] money · ['n' => '12.5'] number · ['d' => 'Y-m-d'] date · ['ym' => 'Y-m'] month
     *   ['k' => key] a label · ['v' => key] a translated value · ['h' => CODE, 't' => name] a pay head
     *
     * @param  string|int|float|array<string, mixed>|null  $cell
     */
    public function cell(string|int|float|array|null $cell): string|HtmlString
    {
        if (! is_array($cell)) {
            if ($cell === null) {
                return '—';
            }
            $text = $this->num((string) $cell);

            // English inside an Urdu (right-to-left) document keeps its own direction, so brackets stay the right way round.
            return $this->rtl() && preg_match('/\p{Arabic}/u', $text) !== 1 ? new HtmlString('<bdo dir="ltr">'.e($text).'</bdo>') : $text;
        }

        return match (true) {
            array_key_exists('m', $cell) => $this->money((int) $cell['m']),
            array_key_exists('n', $cell) => $this->num((string) $cell['n']),
            array_key_exists('d', $cell) => $this->date($cell['d']),
            array_key_exists('ym', $cell) => $this->month((string) $cell['ym']),
            array_key_exists('k', $cell) => $this->label((string) $cell['k']),
            array_key_exists('v', $cell) => $this->both("values.{$cell['v']}", (string) $cell['v']),
            array_key_exists('h', $cell) => $this->head((string) $cell['h'], (string) ($cell['t'] ?? $cell['h'])),
            default => '—',
        };
    }

    /** True for cells printed right-aligned (money and numbers). */
    public static function numeric(mixed $cell): bool
    {
        return is_int($cell) || is_float($cell) || (is_array($cell) && (array_key_exists('m', $cell) || array_key_exists('n', $cell)));
    }

    /** Plain text of a cell (thermal printers, Excel). */
    public function plain(mixed $cell): string
    {
        $v = $this->cell($cell);

        return trim(html_entity_decode(strip_tags(str_replace('<br>', ' ', (string) $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function both(string $key, string $fallback): HtmlString
    {
        return $this->pair($this->en($key, $fallback), $this->ur($key));
    }

    private function pair(string $en, ?string $ur): HtmlString
    {
        return new HtmlString(match (true) {
            $this->language === 'ur' && $ur !== null => '<span class="ur">'.e($ur).'</span>',
            $this->language === 'bilingual' && $ur !== null => e($en).' <span class="ur">'.e($ur).'</span>',
            default => e($en),
        });
    }

    private function en(string $key, string $fallback): string
    {
        $v = __("documents.{$key}", [], 'en');

        return is_string($v) && $v !== "documents.{$key}" ? $v : ucfirst(str_replace('_', ' ', $fallback));
    }

    private function ur(string $key): ?string
    {
        $v = __("documents.{$key}", [], 'ur');

        return is_string($v) && $v !== "documents.{$key}" ? $v : null;
    }
}
