<?php

declare(strict_types=1);

namespace App\Domain\Ops\Backup;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Database dump / restore through the vendor's own tools (pg_dump / pg_restore, mysqldump / mysql),
 * so a backup can be restored without this application. Passwords go through the environment,
 * never the command line.
 */
class DatabaseDumper
{
    /** @param  array<string, mixed>  $connection  Laravel connection config */
    public function dump(array $connection, string $target): void
    {
        $driver = (string) $connection['driver'];
        if ($driver === 'pgsql') {
            $this->run([$this->bin('pg', 'pg_dump'), '--format=custom', '--no-owner', '--no-acl', '--enable-row-security', '--file='.$target,
                '--host='.$connection['host'], '--port='.$connection['port'], '--username='.$connection['username'], (string) $connection['database']],
                self::pgEnv($connection));

            return;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->run([$this->bin('mysql', 'mysqldump'), '--single-transaction', '--routines', '--triggers', '--hex-blob',
                '--result-file='.$target, '--host='.$connection['host'], '--port='.$connection['port'], '--user='.$connection['username'], (string) $connection['database']],
                ['MYSQL_PWD' => (string) $connection['password']]);

            return;
        }
        throw new RuntimeException("Backups are not supported for the {$driver} driver.");
    }

    /** @param  array<string, mixed>  $connection  connection config whose `database` is the (empty) drill database */
    public function restore(array $connection, string $source): void
    {
        $driver = (string) $connection['driver'];
        if ($driver === 'pgsql') {
            $this->run([$this->bin('pg', 'pg_restore'), '--no-owner', '--no-acl', '--clean', '--if-exists', '--exit-on-error', '--enable-row-security',
                '--host='.$connection['host'], '--port='.$connection['port'], '--username='.$connection['username'],
                '--dbname='.$connection['database'], $source],
                self::pgEnv($connection));

            return;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->run([$this->bin('mysql', 'mysql'), '--host='.$connection['host'], '--port='.$connection['port'],
                '--user='.$connection['username'], (string) $connection['database'], '-e', 'source '.str_replace('\\', '/', $source)],
                ['MYSQL_PWD' => (string) $connection['password']]);

            return;
        }
        throw new RuntimeException("Restores are not supported for the {$driver} driver.");
    }

    /**
     * Row-level security is FORCEd on tenant tables, so the dump/restore session must use the
     * application's own system bypass switch (the same one TenantContext::runAsSystem sets).
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, string>
     */
    private static function pgEnv(array $connection): array
    {
        return ['PGPASSWORD' => (string) $connection['password'], 'PGOPTIONS' => '-c app.rls_bypass=on'];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function run(array $command, array $env): void
    {
        $result = Process::timeout(3600)->env($env)->run($command);
        if (! $result->successful()) {
            throw new RuntimeException(basename($command[0]).' failed: '.mb_substr(trim($result->errorOutput()), 0, 300));
        }
    }

    private function bin(string $family, string $tool): string
    {
        $dir = (string) config("ricemill.backup.{$family}_bin");
        $exe = PHP_OS_FAMILY === 'Windows' ? $tool.'.exe' : $tool;

        return $dir === '' ? $tool : rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$exe;
    }
}
