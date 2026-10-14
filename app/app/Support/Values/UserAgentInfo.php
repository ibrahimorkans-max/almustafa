<?php

declare(strict_types=1);

namespace App\Support\Values;

/**
 * Browser and operating system names from a User-Agent header, for the sessions screen
 * ("Chrome on Windows"). Rough by nature: a browser can claim anything, so this is for people to
 * recognise their own sign-ins, never for a security decision.
 */
final class UserAgentInfo
{
    public function __construct(public readonly ?string $browser, public readonly ?string $os) {}

    public static function parse(?string $ua): self
    {
        if ($ua === null || trim($ua) === '') {
            return new self(null, null);
        }
        if (str_starts_with($ua, 'ricemill-agent') || str_contains($ua, 'python-requests') || str_contains($ua, 'Python-urllib')) {
            return new self('Gate agent', str_contains($ua, 'Windows') ? 'Windows' : null);
        }

        $browser = match (true) {
            (bool) preg_match('#Edg(?:A|iOS)?/(\d+)#', $ua, $m) => 'Edge '.$m[1],
            (bool) preg_match('#(?:OPR|Opera)/(\d+)#', $ua, $m) => 'Opera '.$m[1],
            (bool) preg_match('#SamsungBrowser/(\d+)#', $ua, $m) => 'Samsung Internet '.$m[1],
            (bool) preg_match('#Firefox/(\d+)#', $ua, $m), (bool) preg_match('#FxiOS/(\d+)#', $ua, $m) => 'Firefox '.$m[1],
            (bool) preg_match('#CriOS/(\d+)#', $ua, $m) => 'Chrome '.$m[1],
            (bool) preg_match('#Chrome/(\d+)#', $ua, $m) => (str_contains($ua, '; wv)') ? 'Android app ' : 'Chrome ').$m[1],
            (bool) preg_match('#Version/(\d+)[.\d]* .*Safari/#', $ua, $m) => 'Safari '.$m[1],
            str_contains($ua, 'Dart/') => 'Mill app',
            default => 'Other browser',
        };

        $os = match (true) {
            (bool) preg_match('#Android (\d+)#', $ua, $m) => 'Android '.$m[1],
            (bool) preg_match('#(?:iPhone|CPU) OS (\d+)#', $ua, $m) => 'iOS '.$m[1],
            str_contains($ua, 'iPad') => 'iPadOS',
            str_contains($ua, 'Windows NT 10.0') => 'Windows 10/11',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return new self(mb_substr($browser, 0, 60), $os === null ? null : mb_substr($os, 0, 60));
    }

    public function label(): string
    {
        return trim(($this->browser ?? 'Unknown browser').($this->os !== null ? ' on '.$this->os : ''));
    }
}
