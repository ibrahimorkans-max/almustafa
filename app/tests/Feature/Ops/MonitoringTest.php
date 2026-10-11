<?php

declare(strict_types=1);

use App\Domain\Auth\LoginService;
use App\Domain\Ops\Backup\BackupCrypto;
use App\Domain\Ops\Heartbeat;
use App\Domain\Ops\OpsAlerts;
use App\Domain\Ops\Preflight;
use App\Jobs\QueueHeartbeat;
use App\Models\Alert;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\Make;

function recordBackup(int $hoursAgo): void
{
    DB::table('backup_runs')->insert([
        'id' => (string) Str::uuid7(), 'kind' => 'backup', 'status' => 'ok', 'file' => 'x.rmbak', 'offsite' => true, 'details' => '{}',
        'started_at' => now()->subHours($hoursAgo)->utc(), 'finished_at' => now()->subHours($hoursAgo)->utc(),
    ]);
}

describe('health endpoint', function () {
    it('is degraded until the scheduler, queue and backups have reported', function () {
        $res = $this->getJson('/health')->assertStatus(503);
        expect($res->json('status'))->toBe('degraded')
            ->and($res->json('checks.database.ok'))->toBeTrue()
            ->and($res->json('checks.cache.ok'))->toBeTrue()
            ->and($res->json('checks.scheduler.detail'))->toBe('never seen')
            ->and($res->json('checks.backup.detail'))->toBe('no successful backup yet');
    });

    it('is ok when everything is fresh, and flags stale heartbeats and old backups', function () {
        Heartbeat::beat(Heartbeat::SCHEDULER);
        Heartbeat::beat(Heartbeat::QUEUE, ['dispatched_at' => now()->toIso8601String()]);
        recordBackup(3);
        $res = $this->getJson('/health')->assertOk();
        expect($res->json('status'))->toBe('ok')->and($res->json('checks.backup.detail'))->toBe('last good backup 3 h ago');

        $this->travel(10)->minutes();
        expect($this->getJson('/health')->assertStatus(503)->json('checks.scheduler.ok'))->toBeFalse();

        $this->travelBack();
        DB::table('backup_runs')->delete();
        recordBackup(30);
        expect($this->getJson('/health')->assertStatus(503)->json('checks.backup.ok'))->toBeFalse();
    });

    it('reveals nothing useful to an attacker', function () {
        $body = $this->get('/health')->getContent();
        expect($body)->not->toContain((string) config('database.connections.pgsql.host'))
            ->and($body)->not->toContain(base_path())
            ->and($body)->not->toContain(app()->version())
            ->and($body)->not->toContain(PHP_VERSION);
    });

    it('records the queue heartbeat from a queued job', function () {
        dispatch(new QueueHeartbeat(now()->toIso8601String()));
        expect(Heartbeat::ageMinutes(Heartbeat::QUEUE))->toBeLessThan(1.0);
    });
});

describe('ops alerts', function () {
    beforeEach(function () {
        $this->e1 = Make::entity('OA1');
        $this->e2 = Make::entity('OA2');
        $this->owner = Make::account($this->e1, 'OWNER');
    });

    it('alerts every firm when sign-ins fail in a burst, without repeating within the hour', function () {
        config(['ricemill.ops.failed_login_burst' => 3]);
        $fail = function (): void {
            try {
                app(LoginService::class)->verifyPassword('nobody.'.Str::random(4), 'wrong', '203.0.113.9');
            } catch (Throwable) {
            }
        };
        $fail();
        $fail();
        expect(app(OpsAlerts::class)->checkFailedLoginBurst())->toBeNull();
        $fail();
        expect(app(OpsAlerts::class)->checkFailedLoginBurst())->toBe(3);

        $alerts = Make::system(fn () => Alert::query()->where('type', 'failed_login_burst')->get());
        expect($alerts)->toHaveCount(2)
            ->and($alerts->pluck('entity_id')->sort()->values()->all())->toBe(collect([$this->e1->id, $this->e2->id])->sort()->values()->all())
            ->and($alerts->first()->severity)->toBe('critical');
        expect(DB::table('notifications')->where('notifiable_id', $this->owner->id)->count())->toBe(1);

        $fail();
        app(OpsAlerts::class)->checkFailedLoginBurst();
        expect(Make::system(fn () => Alert::query()->where('type', 'failed_login_burst')->count()))->toBe(2);
    });

    it('alerts when a scheduled task exits with an error', function () {
        $task = new ScheduledEvent(app(CacheEventMutex::class), 'php artisan backup:run');
        $task->description('backup:run');
        $task->exitCode = 1;
        event(new ScheduledTaskFinished($task, 1.5));

        $alert = Make::system(fn () => Alert::query()->where('type', 'ops_failure')->first());
        expect($alert)->not->toBeNull()->and($alert->title)->toBe('Failed: backup:run')->and($alert->body)->toContain('Exit code 1');

        $ok = new ScheduledEvent(app(CacheEventMutex::class), 'php artisan audit:verify');
        $ok->exitCode = 0;
        event(new ScheduledTaskFinished($ok, 0.5));
        expect(Make::system(fn () => Alert::query()->where('type', 'ops_failure')->count()))->toBe(2); // one per firm, from the failure only
    });
});

describe('preflight', function () {
    function preflightCheck(array $results, string $name): array
    {
        return collect($results)->firstWhere('check', $name) ?? throw new RuntimeException("no check {$name}");
    }

    it('blocks a development configuration', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $r = app(Preflight::class)->run();
        foreach (['APP_ENV is production', 'APP_URL uses https', 'Mail is really sent', 'Queue runs in workers', 'Backup key is set', 'Off-site backup copy'] as $name) {
            expect(preflightCheck($r, $name)['ok'])->toBeFalse()->and(preflightCheck($r, $name)['level'])->toBe('error');
        }
        $this->artisan('ricemill:preflight')->assertFailed();

        // A database queue that hands a long bulk-document job to a second worker is blocked.
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
        expect(preflightCheck(app(Preflight::class)->run(), 'Queue does not rerun long jobs')['ok'])->toBeFalse();
    });

    it('passes the configuration checks for a production setup', function () {
        Process::fake(['*' => Process::result('pg_dump (PostgreSQL) 17.0')]);
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://erp.example.in',
            'session.secure' => true, 'session.driver' => 'database', 'cache.default' => 'database',
            'queue.default' => 'database', 'mail.default' => 'smtp',
            'ricemill.security.super_admin_require_ip_allowlist' => true,
            'ricemill.security.bootstrap_trust_first_device' => false,
            'ricemill.backup.key' => BackupCrypto::newKey(),
            'ricemill.backup.offsite_disk' => 's3',
        ]);
        $r = app(Preflight::class)->run();
        foreach (['APP_ENV is production', 'APP_DEBUG is off', 'APP_URL uses https', 'Session cookie is secure-only', 'Queue runs in workers', 'Queue does not rerun long jobs',
            'Mail is really sent', 'Super Admin IP allow-list required', 'First-device bootstrap is off', 'Backup key is set',
            'Off-site backup copy', 'All migrations have run'] as $name) {
            expect(preflightCheck($r, $name)['ok'])->toBeTrue("{$name}: ".preflightCheck($r, $name)['detail']);
        }
        expect(collect($r)->firstWhere('check', 'pg_dump is available') ?? collect($r)->firstWhere('check', 'mysqldump is available'))
            ->toMatchArray(['ok' => true]);
    });

    it('checks on PostgreSQL that the app login cannot bypass row-level security', function () {
        requiresPostgres();
        $r = app(Preflight::class)->run();
        expect(preflightCheck($r, 'Database is PostgreSQL')['ok'])->toBeTrue()
            ->and(preflightCheck($r, 'App login cannot bypass row-level security')['ok'])->toBeTrue()
            ->and(preflightCheck($r, 'Row-level security forced on every tenant table')['ok'])->toBeTrue();

        // A tenant table without forced RLS is caught (rolled back with the test transaction).
        DB::statement('ALTER TABLE alerts NO FORCE ROW LEVEL SECURITY');
        expect(preflightCheck(app(Preflight::class)->run(), 'Row-level security forced on every tenant table')['detail'])->toContain('alerts');
    });
});
