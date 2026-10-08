<?php

declare(strict_types=1);

namespace App\Domain\Ops\Backup;

use App\Domain\Accounts\Books;
use App\Domain\Audit\AuditVerifier;
use App\Domain\Inventory\StockLedger;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Encrypted backups (database dump + file store + manifest) with off-site copy and retention, and
 * the restore drill that proves a backup can actually be restored and still verifies.
 *
 * The manifest carries the head hash of every audit chain ("anchoring"): sent to the owner by
 * e-mail, it makes any later rewrite of the audit history detectable even by someone outside.
 */
final class BackupService
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{file: string, size_bytes: int, sha256: string, offsite: bool, manifest: array<string, mixed>} */
    public function run(): array
    {
        $key = BackupCrypto::key((string) config('ricemill.backup.key'));
        $runId = (string) Str::uuid7();
        $started = now();
        $work = $this->workDir();

        try {
            $connection = (array) config('database.connections.'.config('database.default'));
            $this->dumper->dump($connection, $work.'/database.dump');

            $heads = $this->tenant->runAsSystem(fn () => DB::table('audit_chain_heads')->orderBy('chain')->get(['chain', 'seq', 'hash'])
                ->map(fn ($h) => ['chain' => $h->chain, 'seq' => (int) $h->seq, 'hash' => $h->hash])->all());
            $files = $this->collectFiles();
            $manifest = [
                'format' => 1,
                'created_at' => $started->utc()->toIso8601String(),
                'app' => ['name' => config('app.name'), 'env' => config('app.env'), 'version' => self::version()],
                'database' => ['driver' => $connection['driver'], 'sha256' => hash_file('sha256', $work.'/database.dump'), 'bytes' => filesize($work.'/database.dump')],
                'files' => ['count' => count($files), 'bytes' => array_sum(array_column($files, 'bytes'))],
                'audit_chain_heads' => $heads,
            ];

            $zipPath = $work.'/backup.zip';
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the archive.');
            }
            $zip->addFile($work.'/database.dump', 'database.dump');
            foreach ($files as $f) {
                $zip->addFile($f['absolute'], 'files/'.$f['relative']);
            }
            $zip->addFromString('manifest.json', (string) json_encode($manifest + ['file_list' => array_map(fn ($f) => [$f['relative'], $f['sha256']], $files)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();

            $name = sprintf('ricemill-%s-%s.rmbak', $started->utc()->format('Ymd-His'), substr($runId, -6));
            $dir = $this->backupDir();
            BackupCrypto::encryptFile($zipPath, $dir.'/'.$name, $key);
            $sha = (string) hash_file('sha256', $dir.'/'.$name);
            $size = (int) filesize($dir.'/'.$name);
            file_put_contents($dir.'/'.$name.'.sha256', $sha.'  '.$name.PHP_EOL);

            $offsite = $this->copyOffsite($dir.'/'.$name, $name);
            $this->record($runId, 'backup', 'ok', $started, ['file' => $name, 'size_bytes' => $size, 'sha256' => $sha, 'offsite' => $offsite],
                ['database_bytes' => $manifest['database']['bytes'], 'files' => $manifest['files'], 'chains' => count($heads)]);
            $this->prune();
            $this->sendAnchor($manifest, $name, $sha);

            return ['file' => $name, 'size_bytes' => $size, 'sha256' => $sha, 'offsite' => $offsite, 'manifest' => $manifest];
        } catch (Throwable $e) {
            $this->record($runId, 'backup', 'failed', $started, [], ['error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        } finally {
            $this->removeDir($work);
        }
    }

    /**
     * Restore drill: decrypt the latest (or given) backup, check every file against the manifest,
     * restore the dump into the separate drill database and run the audit, books and stock verifiers
     * there. Never touches the live database.
     *
     * @return array{file: string, audit_ok: bool, books_mismatches: int, stock_mismatches: int, files_checked: int}
     */
    public function drill(?string $file = null): array
    {
        $key = BackupCrypto::key((string) config('ricemill.backup.key'));
        $drillDb = (string) config('ricemill.backup.drill_database');
        $live = (array) config('database.connections.'.config('database.default'));
        if ($drillDb === '' || $drillDb === ($live['database'] ?? null)) {
            throw new RuntimeException('Set RICEMILL_BACKUP_DRILL_DATABASE to a separate, empty database (never the live one).');
        }
        $file ??= $this->latest() ?? throw new RuntimeException('There is no backup to drill.');
        $runId = (string) Str::uuid7();
        $started = now();
        $work = $this->workDir();
        $previous = (string) config('database.default');

        try {
            BackupCrypto::decryptFile($this->backupDir().'/'.basename($file), $work.'/backup.zip', $key);
            $zip = new ZipArchive;
            if ($zip->open($work.'/backup.zip') !== true || ! $zip->extractTo($work.'/x')) {
                throw new RuntimeException('The archive inside the backup is damaged.');
            }
            $zip->close();
            $manifest = json_decode((string) file_get_contents($work.'/x/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            if (hash_file('sha256', $work.'/x/database.dump') !== $manifest['database']['sha256']) {
                throw new RuntimeException('The database dump does not match the manifest.');
            }
            foreach ($manifest['file_list'] as [$relative, $sha]) {
                if (hash_file('sha256', $work.'/x/files/'.$relative) !== $sha) {
                    throw new RuntimeException("File {$relative} does not match the manifest.");
                }
            }

            $drill = ['database' => $drillDb] + $live;
            $this->dumper->restore($drill, $work.'/x/database.dump');

            config(['database.connections.backup_drill' => $drill]);
            DB::purge('backup_drill');
            DB::setDefaultConnection('backup_drill');
            $result = $this->tenant->runAsSystem(fn () => [
                'audit_ok' => app(AuditVerifier::class)->verify()->ok(),
                'books_mismatches' => count(app(Books::class)->verify()),
                'stock_mismatches' => count(app(StockLedger::class)->verify()),
            ]);
            $result += ['file' => basename($file), 'files_checked' => count($manifest['file_list'])];
            $ok = $result['audit_ok'] && $result['books_mismatches'] === 0 && $result['stock_mismatches'] === 0;
            DB::setDefaultConnection($previous);
            $this->tenant->applyToDatabase();
            $this->record($runId, 'drill', $ok ? 'ok' : 'failed', $started, ['file' => basename($file)], $result);
            if (! $ok) {
                throw new RuntimeException('The restored copy does not verify: '.json_encode($result));
            }

            return $result;
        } catch (Throwable $e) {
            DB::setDefaultConnection($previous);
            if (! DB::table('backup_runs')->where('id', $runId)->exists()) {
                $this->record($runId, 'drill', 'failed', $started, ['file' => basename((string) $file)], ['error' => mb_substr($e->getMessage(), 0, 500)]);
            }
            throw $e;
        } finally {
            DB::purge('backup_drill');
            $this->removeDir($work);
        }
    }

    public function latest(): ?string
    {
        $files = glob($this->backupDir().'/ricemill-*.rmbak') ?: [];
        sort($files);

        return $files === [] ? null : basename(end($files));
    }

    /** Keeps the newest N daily, one per week for M weeks and one per month for K months. */
    public function prune(): int
    {
        $files = glob($this->backupDir().'/ricemill-*.rmbak') ?: [];
        rsort($files);
        $keep = [];
        $weeks = [];
        $months = [];
        foreach ($files as $i => $path) {
            if (! preg_match('/ricemill-(\d{8})-/', basename($path), $m)) {
                continue;
            }
            $day = CarbonImmutable::createFromFormat('Ymd', $m[1]);
            $week = $day->format('o-W');
            $month = $day->format('Y-m');
            if ($i < (int) config('ricemill.backup.keep_daily')) {
                $keep[$path] = true;
            }
            if (! isset($weeks[$week]) && count($weeks) < (int) config('ricemill.backup.keep_weekly')) {
                $weeks[$week] = $keep[$path] = true;
            }
            if (! isset($months[$month]) && count($months) < (int) config('ricemill.backup.keep_monthly')) {
                $months[$month] = $keep[$path] = true;
            }
        }
        $removed = 0;
        foreach ($files as $path) {
            if (! isset($keep[$path])) {
                @unlink($path);
                @unlink($path.'.sha256');
                $removed++;
            }
        }

        return $removed;
    }

    public function backupDir(): string
    {
        $dir = (string) config('ricemill.backup.path');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create the backup folder.');
        }

        return rtrim($dir, '/\\');
    }

    /** @return list<array{relative: string, absolute: string, sha256: string, bytes: int}> */
    private function collectFiles(): array
    {
        $root = Storage::disk('local')->path('files');
        if (! is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $out[] = ['relative' => $relative, 'absolute' => $file->getPathname(), 'sha256' => (string) hash_file('sha256', $file->getPathname()), 'bytes' => (int) $file->getSize()];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['relative'], $b['relative']));

        return $out;
    }

    private function copyOffsite(string $path, string $name): bool
    {
        $disk = (string) config('ricemill.backup.offsite_disk');
        if ($disk === '') {
            return false;
        }
        $stream = fopen($path, 'rb') ?: throw new RuntimeException('Cannot read the backup.');
        try {
            Storage::disk($disk)->writeStream('ricemill/'.$name, $stream);
            Storage::disk($disk)->put('ricemill/'.$name.'.sha256', (string) file_get_contents($path.'.sha256'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $manifest */
    private function sendAnchor(array $manifest, string $name, string $sha): void
    {
        $to = (string) config('ricemill.backup.anchor_email');
        if ($to === '') {
            return;
        }
        $lines = ["Rice Mill ERP — audit anchor {$manifest['created_at']}", "Backup {$name} (sha256 {$sha})", '', 'Audit chain heads:'];
        foreach ($manifest['audit_chain_heads'] as $h) {
            $lines[] = "{$h['chain']}  seq {$h['seq']}  {$h['hash']}";
        }
        $lines[] = '';
        $lines[] = 'Keep this e-mail. If the audit log were ever rewritten, these hashes would no longer match.';
        Mail::raw(implode("\n", $lines), fn ($m) => $m->to($to)->subject('Rice Mill ERP audit anchor '.substr((string) $manifest['created_at'], 0, 10)));
    }

    /**
     * @param  array{file?: string, size_bytes?: int, sha256?: string, offsite?: bool}  $fields
     * @param  array<string, mixed>  $details
     */
    private function record(string $id, string $kind, string $status, CarbonInterface $started, array $fields, array $details): void
    {
        DB::table('backup_runs')->insert([
            'id' => $id, 'kind' => $kind, 'status' => $status,
            'file' => $fields['file'] ?? null, 'size_bytes' => $fields['size_bytes'] ?? null, 'sha256' => $fields['sha256'] ?? null,
            'offsite' => $fields['offsite'] ?? false, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'started_at' => $started->utc()->format('Y-m-d H:i:s.v'), 'finished_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function workDir(): string
    {
        $dir = storage_path('app/backup-work/'.Str::random(16));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create a working folder.');
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private static function version(): string
    {
        $head = base_path('../.git/HEAD');
        if (! is_file($head)) {
            return 'unknown';
        }
        $ref = trim((string) file_get_contents($head));
        if (str_starts_with($ref, 'ref: ')) {
            $refFile = base_path('../.git/'.substr($ref, 5));

            return is_file($refFile) ? substr(trim((string) file_get_contents($refFile)), 0, 12) : 'unknown';
        }

        return substr($ref, 0, 12);
    }
}
