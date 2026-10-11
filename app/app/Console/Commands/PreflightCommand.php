<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Preflight;
use Illuminate\Console\Command;

final class PreflightCommand extends Command
{
    protected $signature = 'ricemill:preflight {--strict : Treat warnings as errors} {--before-migrate : Deploy step before migrating: pending migrations are expected}';

    protected $description = 'Production readiness checks (configuration, security switches, row-level security, backups, server)';

    public function handle(Preflight $preflight): int
    {
        $results = $preflight->run((bool) $this->option('before-migrate'));
        $this->table(['', 'Check', 'Detail'], array_map(fn ($r) => [
            $r['ok'] ? '<info>ok</info>' : ($r['level'] === 'error' ? '<error>FAIL</error>' : '<comment>warn</comment>'),
            $r['check'], $r['detail'],
        ], $results));

        $errors = count(array_filter($results, fn ($r) => ! $r['ok'] && ($r['level'] === 'error' || $this->option('strict'))));
        $warnings = count(array_filter($results, fn ($r) => ! $r['ok'] && $r['level'] === 'warning'));
        if ($errors > 0) {
            $this->error("{$errors} blocking problem(s). Not ready for production.");

            return self::FAILURE;
        }
        $this->info('Ready for production'.($warnings > 0 ? " ({$warnings} warning(s))." : '.'));

        return self::SUCCESS;
    }
}
