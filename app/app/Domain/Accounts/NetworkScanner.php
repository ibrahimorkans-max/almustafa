<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A network scanner read by the server (eSCL / "AirScan", spoken by most network scanners and printers
 * from HP, Canon, Epson, Brother …): the page on the glass is scanned in colour at 300 dpi, A4, and comes
 * back as a JPEG. A browser cannot drive a scanner itself, so the server does, which is possible where the
 * server and the scanner share the mill's network (the mill server).
 *
 * Only private network addresses are allowed (never the internet, this server, or cloud metadata), and
 * only on a mill server or where RICEMILL_SCANNER is switched on.
 */
final class NetworkScanner
{
    public static function available(): bool
    {
        return (bool) config('ricemill.mill_server') || (bool) config('ricemill.scanner.enabled');
    }

    public static function address(?string $entityId): ?string
    {
        $url = $entityId === null ? null : app(SettingsService::class)->get(SettingsService::SCANNER_URL, $entityId);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** "http://192.168.1.50" or "…/eSCL": refused unless it is a scanner on the private network. */
    public static function validate(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['query'])) {
            throw new BusinessRuleViolation('Give the scanner\'s address, e.g. http://192.168.1.50');
        }
        $ip = filter_var($parts['host'], FILTER_VALIDATE_IP) ? $parts['host'] : gethostbyname($parts['host']);
        $private = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false        // 10/8, 172.16/12, 192.168/16
            && ! str_starts_with($ip, '127.') && ! str_starts_with($ip, '169.254.');
        if (! $private) {
            throw new BusinessRuleViolation('Only a scanner on the mill\'s own network (an address like 192.168.x.x or 10.x.x.x) can be used.');
        }

        return str_ends_with(strtolower($url), '/escl') ? $url : $url.'/eSCL';
    }

    /** Scans the page on the glass; returns the JPEG. */
    public function scan(string $address): string
    {
        if (! self::available()) {
            throw new BusinessRuleViolation('Scanning from here works on the mill server only. Scan to a file and choose "Image" or "PDF".');
        }
        $base = self::validate($address);
        $settings = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<scan:ScanSettings xmlns:scan="http://schemas.hp.com/imaging/escl/2011/05/03" xmlns:pwg="http://www.pwg.org/schemas/2010/12/sm">'
            .'<pwg:Version>2.0</pwg:Version>'
            .'<pwg:ScanRegions><pwg:ScanRegion><pwg:ContentRegionUnits>escl:ThreeHundredthsOfInches</pwg:ContentRegionUnits>'
            .'<pwg:Height>3508</pwg:Height><pwg:Width>2480</pwg:Width><pwg:XOffset>0</pwg:XOffset><pwg:YOffset>0</pwg:YOffset></pwg:ScanRegion></pwg:ScanRegions>'
            .'<scan:Intent>Document</scan:Intent><pwg:InputSource>Platen</pwg:InputSource><scan:ColorMode>RGB24</scan:ColorMode>'
            .'<scan:XResolution>300</scan:XResolution><scan:YResolution>300</scan:YResolution><pwg:DocumentFormat>image/jpeg</pwg:DocumentFormat>'
            .'</scan:ScanSettings>';
        try {
            $job = Http::timeout(20)->withBody($settings, 'text/xml')->withoutRedirecting()->post($base.'/ScanJobs');
            $location = (string) $job->header('Location');
            if ($job->status() !== 201 || $location === '') {
                throw new BusinessRuleViolation('The scanner did not start ('.$job->status().'). Is a page on the glass, and the scanner on?');
            }
            // The job lives on the scanner itself: a relative address, or an absolute one on the same host.
            $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
            $job = str_starts_with(strtolower($location), 'http') ? $location : $origin.'/'.ltrim($location, '/');
            if (parse_url($job, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) {
                throw new BusinessRuleViolation('The scanner answered from another address.');
            }
            $page = Http::timeout(90)->withoutRedirecting()->get(rtrim($job, '/').'/NextDocument');
        } catch (BusinessRuleViolation $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BusinessRuleViolation('The scanner could not be reached: '.mb_substr($e->getMessage(), 0, 150));
        }
        if (! $page->successful() || $page->body() === '') {
            throw new BusinessRuleViolation('The scanner gave no page ('.$page->status().').');
        }

        return $page->body();
    }
}
