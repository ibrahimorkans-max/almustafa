<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\GeoIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Downloads this month's DB-IP "IP to City Lite" database (or last month's, early in a month) and
 * swaps it in only after it opens and answers a lookup. A failed download keeps the old file.
 */
final class GeoIpUpdateCommand extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Downloads the free IP-to-city database used to show where sign-ins come from';

    public function handle(): int
    {
        $target = GeoIp::path();
        @mkdir(dirname($target), 0750, true);
        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $url = sprintf((string) config('ricemill.geoip.url'), $month->format('Y-m'));
            try {
                $response = Http::timeout(120)->get($url);
            } catch (Throwable $e) {
                $this->warn("{$url}: {$e->getMessage()}");

                continue;
            }
            if (! $response->successful()) {
                $this->warn("{$url}: HTTP {$response->status()}");

                continue;
            }
            $bytes = @gzdecode($response->body());
            if ($bytes === false || $bytes === '') {
                $this->warn("{$url}: not a gzip file");

                continue;
            }
            $tmp = $target.'.new';
            file_put_contents($tmp, $bytes);
            try {
                $reader = new Reader($tmp);
                $reader->get('8.8.8.8');
                $reader->close();
            } catch (Throwable $e) {
                @unlink($tmp);
                $this->warn("{$url}: the file does not open ({$e->getMessage()})");

                continue;
            }
            rename($tmp, $target);
            $this->info('Location database updated ('.$month->format('Y-m').', '.number_format(strlen($bytes) / 1048576, 1).' MB).');

            return self::SUCCESS;
        }
        $this->error('No location database could be downloaded; the previous one (if any) is kept.');

        return self::FAILURE;
    }
}
