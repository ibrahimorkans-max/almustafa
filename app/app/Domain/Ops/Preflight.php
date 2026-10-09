<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Domain\Ops\Backup\BackupCrypto;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Production readiness checks, run before go-live and by every deploy. An "error" blocks the
 * deploy; a "warning" is reported but does not. Each check says what to fix, never a secret.
 */
final class Preflight
{
    /**
     * Installation-level tables that name an entity but must be readable from every firm's requests:
     * a whole-system lock (entity_id NULL) has to be visible to everyone, which a firm-scoped
     * row-level policy would hide. They hold no business data and change only through audited services.
     */
    private const INSTALLATION_TABLES = ['system_locks'];

    /**
     * @param  bool  $beforeMigrate  deploy step before `migrate`: migrations still to run are expected, not a failure
     * @return list<array{check: string, level: 'error'|'warning', ok: bool, detail: string}>
     */
    public function run(bool $beforeMigrate = false): array
    {
        $out = [];
        $add = function (string $check, string $level, bool $ok, string $detail) use (&$out): void {
            $out[] = ['check' => $check, 'level' => $level, 'ok' => $ok, 'detail' => $detail];
        };

        // Application
        $add('APP_ENV is production', 'error', config('app.env') === 'production', (string) config('app.env'));
        $add('APP_DEBUG is off', 'error', ! config('app.debug'), config('app.debug') ? 'debug pages would leak data' : 'off');
        $add('APP_KEY is set', 'error', strlen((string) config('app.key')) >= 32, config('app.key') ? 'set' : 'missing');
        $add('APP_URL uses https', 'error', str_starts_with((string) config('app.url'), 'https://'), (string) config('app.url'));
        $add('Session cookie is secure-only', 'error', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE=true');
        $add('Sessions are not in memory', 'error', ! in_array(config('session.driver'), ['array', 'file'], true), (string) config('session.driver'));
        $add('HSTS is on', 'warning', (bool) config('ricemill.security.hsts'), 'RICEMILL_HSTS');
        $add('Cache is shared', 'error', ! in_array(config('cache.default'), ['array', 'null'], true), (string) config('cache.default'));
        $add('Queue runs in workers', 'error', ! in_array(config('queue.default'), ['sync', 'null'], true), (string) config('queue.default'));
        $add('Mail is really sent', 'error', ! in_array(config('mail.default'), ['log', 'array'], true), (string) config('mail.default'));
        $add('Log level is not debug', 'warning', config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug', 'LOG_LEVEL');

        // Security switches
        $add('Super Admin IP allow-list required', 'error', (bool) config('ricemill.security.super_admin_require_ip_allowlist'), 'RICEMILL_SA_REQUIRE_IP_ALLOWLIST=true');
        $add('Super Admin trusted devices required', 'error', (bool) config('ricemill.security.super_admin_require_trusted_device'), 'RICEMILL_SA_REQUIRE_TRUSTED_DEVICE=true');
        $add('First-device bootstrap is off', 'error', ! config('ricemill.security.bootstrap_trust_first_device'), 'RICEMILL_BOOTSTRAP_TRUST_FIRST_DEVICE=false after installation');
        $add('Separate blind-index key', 'warning', (string) config('ricemill.security.blind_index_key') !== '', 'RICEMILL_BLIND_INDEX_KEY (otherwise derived from APP_KEY)');
        $add('No demo credentials file', 'error', ! is_file(storage_path('app/private/demo-credentials.txt')), 'delete storage/app/private/demo-credentials.txt');

        // Database
        $driver = DB::connection()->getDriverName();
        $add('Database is PostgreSQL', 'error', $driver === 'pgsql', $driver.' (row-level security needs PostgreSQL)');
        if ($driver === 'pgsql') {
            try {
                $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
                $add('App login cannot bypass row-level security', 'error', $role !== null && ! $role->rolsuper && ! $role->rolbypassrls,
                    $role === null ? 'unknown role' : ($role->rolsuper ? 'login is a superuser' : ($role->rolbypassrls ? 'login has BYPASSRLS' : 'ok')));
                $noRls = array_values(array_filter(DB::select("select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
                    join information_schema.columns k on k.table_schema = n.nspname and k.table_name = c.relname and k.column_name = 'entity_id'
                    where n.nspname = current_schema() and c.relkind = 'r' and not (c.relrowsecurity and c.relforcerowsecurity)"),
                    fn (object $r) => ! in_array($r->relname, self::INSTALLATION_TABLES, true)));
                $add('Row-level security forced on every tenant table', 'error', $noRls === [],
                    $noRls === [] ? 'ok' : 'missing on: '.implode(', ', array_map(fn ($r) => $r->relname, array_slice($noRls, 0, 8))));
            } catch (Throwable $e) {
                $add('Row-level security', 'error', false, mb_substr($e->getMessage(), 0, 120));
            }
        }
        $pending = $this->pendingMigrations();
        if ($beforeMigrate) {
            $add('Migrations to run in this deploy', 'warning', true, $pending === 0 ? 'none' : "{$pending} pending (run next)");
        } else {
            $add('All migrations have run', 'error', $pending === 0, $pending === 0 ? 'ok' : "{$pending} pending");
        }

        // Backups
        try {
            BackupCrypto::key((string) config('ricemill.backup.key'));
            $add('Backup key is set', 'error', true, 'ok');
        } catch (Throwable) {
            $add('Backup key is set', 'error', false, 'run `php artisan backup:key` and store it in RICEMILL_BACKUP_KEY and off the server');
        }
        $add('Off-site backup copy', 'error', (string) config('ricemill.backup.offsite_disk') !== '', 'RICEMILL_BACKUP_OFFSITE_DISK');
        $add('Restore drill database', 'warning', (string) config('ricemill.backup.drill_database') !== '', 'RICEMILL_BACKUP_DRILL_DATABASE');
        $add('Audit anchor e-mail', 'warning', (string) config('ricemill.backup.anchor_email') !== '', 'RICEMILL_AUDIT_ANCHOR_EMAIL');
        $tool = $driver === 'pgsql' ? 'pg_dump' : 'mysqldump';
        $add("{$tool} is available", 'error', $this->toolWorks($driver === 'pgsql' ? 'pg' : 'mysql', $tool), 'install the client tools or set RICEMILL_PG_BIN / RICEMILL_MYSQL_BIN');

        // Server
        foreach (['sodium', 'zip', 'bcmath', 'intl', 'gd', 'mbstring', 'pdo_pgsql'] as $ext) {
            $add("PHP extension {$ext}", 'error', extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing');
        }
        $add('OPcache is on', 'warning', function_exists('opcache_get_status') && (bool) ini_get('opcache.enable'), 'opcache.enable=1');
        foreach ([storage_path(), base_path('bootstrap/cache')] as $dir) {
            $add('Writable: '.basename($dir), 'error', is_writable($dir), $dir);
        }

        // Printed documents and branding
        $brand = config('ricemill.brand');
        $files = [$brand['logo_wide'], $brand['logo_wide_dark'], $brand['favicon'], 'images/brand/logo-wide-print.png', 'images/brand/icon-192.png', 'images/brand/icon-512.png'];
        $missing = array_values(array_filter($files, fn (string $f) => ! is_file(public_path($f))));
        $add('Logo and icon files present', 'error', $missing === [], $missing === [] ? 'public/images/brand' : 'missing: '.implode(', ', $missing));
        $pdfTmp = storage_path('framework/cache');
        $add('PDF engine can write its cache', 'error', is_dir($pdfTmp) && is_writable($pdfTmp), $pdfTmp);
        $add('PDF fonts present (Urdu: Lateef)', 'error', is_file(base_path('vendor/mpdf/mpdf/ttfonts/LateefRegOT.ttf')), 'vendor/mpdf/mpdf/ttfonts');
        try {
            $mills = app(TenantContext::class)->runAsSystem(fn () => Entity::query()->get());
            $noNtn = $mills->filter(fn (Entity $e) => blank($e->ntn))->pluck('code')->all();
            $add('Mill NTN entered (printed on invoices)', 'warning', $mills->isNotEmpty() && $noNtn === [], $mills->isEmpty() ? 'no mill set up yet' : ($noNtn === [] ? 'ok' : 'Entities without NTN: '.implode(', ', $noNtn)));
        } catch (Throwable) {
            $add('Mill NTN entered (printed on invoices)', 'warning', false, 'entities could not be read');
        }
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $pct = $free !== false && $total ? (int) round($free * 100 / $total) : 0;
        $add('Free disk space', 'error', $pct >= (int) config('ricemill.ops.min_free_disk_percent'), "{$pct}% free");

        return $out;
    }

    private function pendingMigrations(): int
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
        $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);

        return count(array_diff($files, $ran));
    }

    private function toolWorks(string $family, string $tool): bool
    {
        $dir = (string) config("ricemill.backup.{$family}_bin");
        $exe = $dir === '' ? $tool : rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$tool.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        try {
            return Process::timeout(15)->run([$exe, '--version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
