<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes hash chains and reports the first broken link per chain:
 * a gap or reordering in seq, a prev_hash that does not match, a row whose
 * content no longer matches its hash, or a head that does not match the last row.
 */
final class AuditVerifier
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<string>|null  $chains  null = every chain
     */
    public function verify(?array $chains = null): AuditVerificationResult
    {
        return $this->tenant->runAsSystem(function () use ($chains): AuditVerificationResult {
            $chains ??= DB::table('audit_chain_heads')->orderBy('chain')->pluck('chain')->all();
            $result = new AuditVerificationResult;

            foreach ($chains as $chain) {
                $this->verifyChain($chain, $result);
            }

            return $result;
        });
    }

    public function verifyEntity(string $entityId): AuditVerificationResult
    {
        return $this->verify(["entity:{$entityId}"]);
    }

    private function verifyChain(string $chain, AuditVerificationResult $result): void
    {
        $expectedSeq = 1;
        $prevHash = AuditHasher::GENESIS;
        $broken = false;

        DB::table('audit_log')
            ->where('chain', $chain)
            ->orderBy('seq')
            ->chunk(500, function ($rows) use ($chain, $result, &$expectedSeq, &$prevHash, &$broken): bool {
                foreach ($rows as $row) {
                    $seq = (int) $row->seq;
                    $error = match (true) {
                        $seq !== $expectedSeq => "expected seq {$expectedSeq}, found {$seq}",
                        $row->prev_hash !== $prevHash => 'prev_hash does not match previous row',
                        AuditHasher::hash($row->prev_hash, $row) !== $row->hash => 'row content does not match its hash',
                        default => null,
                    };

                    if ($error !== null) {
                        $result->addFailure($chain, $seq, $error);
                        $broken = true;

                        return false;
                    }

                    $result->countRow();
                    $prevHash = $row->hash;
                    $expectedSeq++;
                }

                return true;
            });

        $result->countChain();
        if ($broken) {
            return;
        }

        $head = DB::table('audit_chain_heads')->where('chain', $chain)->first();
        if ($head !== null && ((int) $head->seq !== $expectedSeq - 1 || $head->hash !== $prevHash)) {
            $result->addFailure($chain, (int) $head->seq, 'chain head does not match last row (rows missing?)');
        }
    }
}
