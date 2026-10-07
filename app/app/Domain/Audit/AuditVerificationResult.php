<?php

declare(strict_types=1);

namespace App\Domain\Audit;

final class AuditVerificationResult
{
    public int $chains = 0;

    public int $rows = 0;

    /** @var list<array{chain: string, seq: int, error: string}> */
    public array $failures = [];

    public function ok(): bool
    {
        return $this->failures === [];
    }

    public function countChain(): void
    {
        $this->chains++;
    }

    public function countRow(): void
    {
        $this->rows++;
    }

    public function addFailure(string $chain, int $seq, string $error): void
    {
        $this->failures[] = ['chain' => $chain, 'seq' => $seq, 'error' => $error];
    }

    /** @return array{ok: bool, chains: int, rows: int, failures: list<array{chain: string, seq: int, error: string}>} */
    public function toArray(): array
    {
        return ['ok' => $this->ok(), 'chains' => $this->chains, 'rows' => $this->rows, 'failures' => $this->failures];
    }
}
