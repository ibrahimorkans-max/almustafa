<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * What every value typed or sent into the system goes through (SanitizeInput, the SafeText rule on every form field).
 *
 * clean():  invisible characters that have no place in text are removed: NUL and other control characters (tab and
 *           new lines stay), the direction overrides used to disguise text ("Trojan Source"), the byte-order mark,
 *           and broken UTF-8. Urdu's joiners (ZWJ / ZWNJ) stay.
 * threat(): what a form refuses with a message: HTML or script code (tags, javascript: links, HTML data links).
 * strip():  the same code taken out, for values that are not form fields (searches, phone sync, live boxes).
 *
 * SQL injection is stopped where it happens (every query binds its values; nothing typed is put into SQL), and
 * every screen escapes what it shows; this is the line before both.
 */
final class InputGuard
{
    /** Control characters except tab / LF / CR, DEL and C1, direction overrides and isolates, BOM. */
    private const INVISIBLE = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /** A real HTML tag or comment: "<b>", "</div>", "<img src=x>", "<!-- … -->", "<!doctype". Not "a < b" or "<5". */
    private const TAG = '/<\s*\/?\s*[a-z][a-z0-9:-]*(?:\s[^<>]*)?\/?\s*>|<!--|<!\s*[a-z\[]|<\?(?:php|=)?/iu';

    /** A dangerous tag even when left unclosed ("<script", "<svg onload=…"). */
    private const OPEN_TAG = '/<\s*\/?\s*(?:script|iframe|frame|object|embed|applet|svg|math|img|image|video|audio|source|style|link|meta|base|form|input|button|textarea|select|body|html|marquee|details|template|xml)\b/iu';

    /** Links that run code: javascript:, vbscript:, livescript:, and HTML/script data: links (spaces and entity tricks too). */
    private const SCHEME = '/(?:java|vb|live)\s*s\s*c\s*r\s*i\s*p\s*t\s*:|data\s*:\s*(?:text\/html|text\/javascript|application\/(?:x-)?javascript|image\/svg\+xml)/iu';

    /** Encoded forms of "<" used to slip a tag past a check (&lt; shown as text is harmless, so only the numeric ones). */
    private const ENCODED_LT = '/&#0*(?:60|x0*3c);?\s*\/?\s*[a-z]/iu';

    public static function clean(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_scrub($value, 'UTF-8');
        }

        return (string) preg_replace(self::INVISIBLE, '', $value);
    }

    /** Why a value may not be saved, or null when it is fine. */
    public static function threat(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return 'contains characters that are not valid text.';
        }
        $plain = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ([$value, $plain] as $v) {
            if (preg_match(self::TAG, $v) === 1 || preg_match(self::OPEN_TAG, $v) === 1) {
                return 'may not contain HTML or script code (for example <b> or <script>).';
            }
            if (preg_match(self::SCHEME, $v) === 1) {
                return 'may not contain a script link (javascript: or an HTML data: link).';
            }
        }
        if (preg_match(self::ENCODED_LT, $value) === 1) {
            return 'may not contain encoded HTML.';
        }

        return null;
    }

    /** The value with any HTML / script code taken out (and cleaned). */
    public static function strip(string $value): string
    {
        $value = self::clean($value);
        if (self::threat($value) === null) {
            return $value;
        }
        $value = (string) preg_replace(self::ENCODED_LT, '', $value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Whole script / style blocks go with their contents; other tags just lose their brackets' contents.
        $value = (string) preg_replace('/<\s*(script|style|iframe|object|embed|svg|math|template)\b.*?(?:<\s*\/\s*\1\s*>|$)/isu', '', $value);
        $value = (string) preg_replace([self::TAG, self::OPEN_TAG, self::SCHEME], '', $value);
        $value = self::clean($value);

        // Whatever is left that still looks like code is refused outright.
        return self::threat($value) === null ? $value : (string) preg_replace('/[<>]/u', '', $value);
    }

    /** A file name safe for a download header and the disk: no path, quotes, control characters or odd symbols. */
    public static function filename(string $name, string $fallback = 'file'): string
    {
        $name = self::clean(basename(str_replace('\\', '/', $name)));
        $name = (string) preg_replace('/[^\p{L}\p{N}._\- ()]+/u', '-', $name);
        $name = trim((string) preg_replace('/-{2,}/', '-', $name), ' .-');

        return $name === '' ? $fallback : mb_substr($name, 0, 150);
    }
}
