<?php

declare(strict_types=1);

use App\Domain\Ops\Backup\BackupCrypto;
use App\Domain\Ops\Backup\BackupService;
use App\Domain\Ops\Backup\DatabaseDumper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Make;

/** Writes a recognisable "dump" and remembers restores, so no database tools are needed. */
final class FakeDumper extends DatabaseDumper
{
    /** @var list<array{0: string, 1: string}> */
    public array $restored = [];

    public function dump(array $connection, string $target): void
    {
        file_put_contents($target, "-- fake dump of {$connection['database']}\n".str_repeat('x', 200_000));
    }

    public function restore(array $connection, string $source): void
    {
        $this->restored[] = [(string) $connection['database'], (string) file_get_contents($source, length: 40)];
    }
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/rm-backup-test-'.Str::random(8);
    config([
        'ricemill.backup.key' => BackupCrypto::newKey(),
        'ricemill.backup.path' => $this->dir,
        'ricemill.backup.offsite_disk' => '',
        'ricemill.backup.anchor_email' => '',
    ]);
    $this->dumper = new FakeDumper;
    app()->instance(DatabaseDumper::class, $this->dumper);
    Storage::fake('local');
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

function backupTmpFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'rmt');
    file_put_contents($path, $content);

    return $path;
}

describe('backup encryption', function () {
    it('round-trips files of any size, including empty and multi-chunk', function () {
        $key = BackupCrypto::key(BackupCrypto::newKey());
        foreach (['', 'short', random_bytes(65536), random_bytes(200_001)] as $plain) {
            $in = backupTmpFile($plain);
            BackupCrypto::encryptFile($in, $in.'.enc', $key);
            expect(file_get_contents($in.'.enc'))->not->toContain($plain === '' ? 'never-matches' : substr($plain, 0, 16));
            BackupCrypto::decryptFile($in.'.enc', $in.'.dec', $key);
            expect(file_get_contents($in.'.dec'))->toBe($plain);
            array_map('unlink', [$in, $in.'.enc', $in.'.dec']);
        }
    });

    it('refuses a wrong key, a changed byte, a truncated file and trailing data', function () {
        $key = BackupCrypto::key(BackupCrypto::newKey());
        $in = backupTmpFile(random_bytes(150_000));
        BackupCrypto::encryptFile($in, $in.'.enc', $key);
        $good = (string) file_get_contents($in.'.enc');

        expect(fn () => BackupCrypto::decryptFile($in.'.enc', $in.'.dec', BackupCrypto::key(BackupCrypto::newKey())))
            ->toThrow(RuntimeException::class, 'wrong key or the file was changed');

        $bad = $good;
        $bad[70_000] = chr(ord($bad[70_000]) ^ 1);
        file_put_contents($in.'.enc', $bad);
        expect(fn () => BackupCrypto::decryptFile($in.'.enc', $in.'.dec', $key))->toThrow(RuntimeException::class, 'wrong key or the file was changed');

        file_put_contents($in.'.enc', substr($good, 0, 70_000));
        expect(fn () => BackupCrypto::decryptFile($in.'.enc', $in.'.dec', $key))->toThrow(RuntimeException::class);

        file_put_contents($in.'.enc', $good.'extra');
        expect(fn () => BackupCrypto::decryptFile($in.'.enc', $in.'.dec', $key))->toThrow(RuntimeException::class, 'Unexpected data');

        file_put_contents($in.'.enc', 'PK'.$good);
        expect(fn () => BackupCrypto::decryptFile($in.'.enc', $in.'.dec', $key))->toThrow(RuntimeException::class, 'Not a Rice Mill ERP backup');
        array_map('unlink', [$in, $in.'.enc', $in.'.dec']);
    });

    it('accepts only a 32-byte base64 key', function () {
        expect(fn () => BackupCrypto::key(''))->toThrow(RuntimeException::class)
            ->and(fn () => BackupCrypto::key(base64_encode(random_bytes(16))))->toThrow(RuntimeException::class)
            ->and(strlen(BackupCrypto::key(BackupCrypto::newKey())))->toBe(32);
    });
});

describe('backup:run', function () {
    it('writes an encrypted archive with the dump, files and audit-chain heads, and records the run', function () {
        $entity = Make::entity('BK1'); // provisioning writes audit rows → at least one chain head
        Storage::disk('local')->put('files/'.$entity->id.'/ab/abcdef', 'weighbridge photo');
        config(['ricemill.backup.anchor_email' => 'owner@example.test']);

        $this->artisan('backup:run')->assertSuccessful();

        $file = app(BackupService::class)->latest();
        expect($file)->toMatch('/^ricemill-\d{8}-\d{6}-[0-9a-f]{6}\.rmbak$/');
        $raw = (string) file_get_contents($this->dir.'/'.$file);
        expect(substr($raw, 0, 6))->toBe('RMBAK1')
            ->and($raw)->not->toContain('fake dump')
            ->and($raw)->not->toContain('weighbridge photo')
            ->and(file_get_contents($this->dir.'/'.$file.'.sha256'))->toStartWith(hash('sha256', $raw));

        // Decrypt with the key and look inside.
        BackupCrypto::decryptFile($this->dir.'/'.$file, $this->dir.'/plain.zip', BackupCrypto::key((string) config('ricemill.backup.key')));
        $zip = new ZipArchive;
        $zip->open($this->dir.'/plain.zip');
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $heads = Make::system(fn () => DB::table('audit_chain_heads')->count());
        expect($zip->getFromName('database.dump'))->toStartWith('-- fake dump')
            ->and($zip->getFromName('files/'.$entity->id.'/ab/abcdef'))->toBe('weighbridge photo')
            ->and($manifest['files']['count'])->toBe(1)
            ->and($manifest['audit_chain_heads'])->toHaveCount($heads)
            ->and($heads)->toBeGreaterThan(0)
            ->and($manifest['audit_chain_heads'][0])->toHaveKeys(['chain', 'seq', 'hash']);
        $zip->close();

        $run = DB::table('backup_runs')->where('kind', 'backup')->first();
        expect($run->status)->toBe('ok')->and($run->file)->toBe($file)->and($run->sha256)->toBe(hash('sha256', $raw));

        // The anchor e-mail carries the chain heads (never the key).
        $messages = app('mailer')->getSymfonyTransport()->messages();
        expect($messages)->toHaveCount(1);
        $body = $messages->first()->getOriginalMessage()->getTextBody();
        expect($body)->toContain($manifest['audit_chain_heads'][0]['hash'])
            ->and($body)->toContain($file)
            ->and($body)->not->toContain((string) config('ricemill.backup.key'));
    });

    it('copies the backup off-site when a disk is configured', function () {
        Storage::fake('offsite');
        config(['ricemill.backup.offsite_disk' => 'offsite']);
        $r = app(BackupService::class)->run();
        expect($r['offsite'])->toBeTrue()
            ->and(Storage::disk('offsite')->exists('ricemill/'.$r['file']))->toBeTrue()
            ->and(Storage::disk('offsite')->get('ricemill/'.$r['file'].'.sha256'))->toContain($r['sha256']);
    });

    it('fails loudly without a valid key and records failed dumps', function () {
        config(['ricemill.backup.key' => '']);
        $this->artisan('backup:run')->assertFailed();

        config(['ricemill.backup.key' => BackupCrypto::newKey()]);
        app()->instance(DatabaseDumper::class, new class extends DatabaseDumper
        {
            public function dump(array $connection, string $target): void
            {
                throw new RuntimeException('pg_dump: connection refused');
            }
        });
        $this->artisan('backup:run')->assertFailed();
        $run = DB::table('backup_runs')->where('status', 'failed')->first();
        expect($run)->not->toBeNull()->and($run->details)->toContain('connection refused')
            ->and(glob($this->dir.'/*.rmbak') ?: [])->toBe([]);
    });

    it('keeps 14 daily, 8 weekly and 12 monthly backups', function () {
        mkdir($this->dir, 0700, true);
        $day = CarbonImmutable::parse('2026-10-08');
        for ($i = 0; $i < 400; $i++) {
            $name = 'ricemill-'.$day->subDays($i)->format('Ymd').'-013000-aaaaaa.rmbak';
            touch($this->dir.'/'.$name);
            touch($this->dir.'/'.$name.'.sha256');
        }
        $removed = app(BackupService::class)->prune();
        $left = array_map('basename', glob($this->dir.'/*.rmbak'));
        sort($left);

        expect($removed)->toBe(400 - count($left))
            ->and(count($left))->toBeLessThanOrEqual(14 + 8 + 12)
            ->and($left)->toContain('ricemill-20261008-013000-aaaaaa.rmbak', 'ricemill-20260925-013000-aaaaaa.rmbak')
            ->and($left)->not->toContain('ricemill-20250101-013000-aaaaaa.rmbak')
            ->and(count(glob($this->dir.'/*.sha256')))->toBe(count($left));
        // The oldest kept backup is about a year old (monthly tier).
        expect($left[0])->toStartWith('ricemill-2025');
    });
});

describe('restore drill', function () {
    it('refuses to restore into the live database or without a drill database', function () {
        app(BackupService::class)->run();
        config(['ricemill.backup.drill_database' => '']);
        expect(fn () => app(BackupService::class)->drill())->toThrow(RuntimeException::class, 'separate, empty database');
        config(['ricemill.backup.drill_database' => config('database.connections.'.config('database.default').'.database')]);
        expect(fn () => app(BackupService::class)->drill())->toThrow(RuntimeException::class, 'never the live one');
        expect($this->dumper->restored)->toBe([]);
    });

    it('stops before restoring a backup that was changed', function () {
        Storage::disk('local')->put('files/x/aa/1', 'one');
        $r = app(BackupService::class)->run();
        $path = $this->dir.'/'.$r['file'];
        $raw = (string) file_get_contents($path);
        $raw[200] = chr(ord($raw[200]) ^ 1);
        file_put_contents($path, $raw);
        config(['ricemill.backup.drill_database' => 'ricemill_drill_nonexistent']);

        $this->artisan('backup:verify')->assertFailed();
        expect($this->dumper->restored)->toBe([])
            ->and(DB::table('backup_runs')->where('kind', 'drill')->value('status'))->toBe('failed');
    });

    it('restores a real pg_dump into the drill database and verifies it (needs RICEMILL_TEST_PG_BIN)', function () {
        requiresPostgres();
        $bin = (string) getenv('RICEMILL_TEST_PG_BIN');
        $drill = (string) (getenv('RICEMILL_TEST_DRILL_DATABASE') ?: 'ricemill_drill');
        if ($bin === '') {
            $this->markTestSkipped('Set RICEMILL_TEST_PG_BIN (folder of pg_dump) and an empty RICEMILL_TEST_DRILL_DATABASE to run.');
        }
        app()->forgetInstance(DatabaseDumper::class);
        app()->bind(DatabaseDumper::class, DatabaseDumper::class);
        config(['ricemill.backup.pg_bin' => $bin, 'ricemill.backup.drill_database' => $drill]);

        app(BackupService::class)->run();
        $r = app(BackupService::class)->drill();

        expect($r['audit_ok'])->toBeTrue()->and($r['books_mismatches'])->toBe(0)->and($r['stock_mismatches'])->toBe(0)
            ->and(DB::getDefaultConnection())->toBe('pgsql')
            ->and(DB::table('backup_runs')->where('kind', 'drill')->value('status'))->toBe('ok');
    });
});
