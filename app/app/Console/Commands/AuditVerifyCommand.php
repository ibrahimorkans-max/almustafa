<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditVerifier;
use Illuminate\Console\Command;

final class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify {--entity= : Verify only this entity\'s chain}';

    protected $description = 'Recompute the audit hash chains and report any tampering';

    public function handle(AuditVerifier $verifier): int
    {
        $entity = $this->option('entity');
        $result = $entity ? $verifier->verifyEntity((string) $entity) : $verifier->verify();

        $this->line("Chains: {$result->chains}, rows: {$result->rows}");
        if ($result->ok()) {
            $this->info('Audit log intact.');

            return self::SUCCESS;
        }

        $this->table(['chain', 'seq', 'error'], $result->failures);
        $this->error('Audit log verification FAILED.');

        return self::FAILURE;
    }
}
