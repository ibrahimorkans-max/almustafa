<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness for an uptime monitor. Reports only states and ages — no versions, paths, hosts or
 * figures that could help an attacker.
 */
final class HealthCheck
{
    /** @return array{status: string, checks: array<string, array{ok: bool, detail: string}>} */
    public function run(): array
    {
        $checks = [];

        $checks['database'] = $this->check(fn () => DB::select('select 1 as ok') !== [] ? 'reachable' : throw new \RuntimeException('no answer'));
        $checks['cache'] = $this->check(function (): string {
            $key = 'health:'.bin2hex(random_bytes(4));
            Cache::put($key, 'ok', 10);
            $ok = Cache::pull($key) === 'ok';

            return $ok ? 'read / write' : throw new \RuntimeException('cache round trip failed');
        });
        $checks['disk'] = $this->check(function (): string {
            $free = @disk_free_space(storage_path());
            $total = @disk_total_space(storage_path());
            if ($free === false || $total === false || $total <= 0) {
                throw new \RuntimeException('unknown');
            }
            $pct = (int) round($free * 100 / $total);

            return $pct >= (int) config('ricemill.ops.min_free_disk_percent') ? "{$pct}% free" : throw new \RuntimeException("only {$pct}% free");
        });
        foreach ([Heartbeat::SCHEDULER => 'scheduler_max_minutes', Heartbeat::QUEUE => 'queue_max_minutes'] as $name => $limitKey) {
            $age = Heartbeat::ageMinutes($name);
            $limit = (float) config("ricemill.ops.{$limitKey}");
            $checks[$name] = ['ok' => $age !== null && $age <= $limit, 'detail' => $age === null ? 'never seen' : "last seen {$age} min ago"];
        }
        $last = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('finished_at');
        $hours = $last === null ? null : round(now()->diffInMinutes(CarbonImmutable::parse((string) $last, 'UTC'), true) / 60, 1);
        $checks['backup'] = ['ok' => $hours !== null && $hours <= (float) config('ricemill.ops.backup_max_hours'),
            'detail' => $hours === null ? 'no successful backup yet' : "last good backup {$hours} h ago"];

        $ok = ! in_array(false, array_column($checks, 'ok'), true);

        return ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks];
    }

    /**
     * @param  callable(): string  $probe
     * @return array{ok: bool, detail: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'detail' => $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 80)];
        }
    }
}
