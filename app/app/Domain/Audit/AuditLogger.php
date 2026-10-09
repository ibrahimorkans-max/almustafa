<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Auth\ActorContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of `audit_log`.
 *
 * Each entity has its own chain ("entity:<uuid>"); events without an entity go to "global".
 * The chain head row is locked FOR UPDATE, so concurrent writers to one chain are serialised
 * and seq has no gaps. Call inside the business transaction so the audit row commits or
 * rolls back together with the change it describes.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>  $meta
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        array $meta = [],
        ?string $entityId = null,
    ): string {
        $entityId ??= $this->entityIdFor($subject);
        $chain = $entityId !== null ? "entity:{$entityId}" : 'global';

        $row = [
            'id' => (string) Str::uuid7(),
            'chain' => $chain,
            'entity_id' => $entityId,
            'occurred_at' => AuditHasher::formatTimestamp(CarbonImmutable::now('UTC')),
            'actor_login_account_id' => $this->actor->accountId(),
            'actor_person_id' => $this->actor->personId(),
            'acting_as_super_admin' => $this->actor->isSuperAdmin(),
            'device_id' => $this->actor->deviceId(),
            'ip' => $this->actor->ip(),
            'user_agent' => $this->actor->userAgent(),
            'action' => $action,
            'subject_type' => $subject?->getTable(),
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'old_values' => CanonicalJson::encode($old),
            'new_values' => CanonicalJson::encode($new),
            'meta' => $meta === [] ? null : CanonicalJson::encode($meta),
        ];

        // Audit writes are trusted internal code: they may target the global chain or,
        // for Super Admin, another entity's chain, so they run with isolation bypassed.
        $this->tenant->runAsSystem(function () use (&$row, $chain): void {
            DB::transaction(function () use (&$row, $chain): void {
                DB::table('audit_chain_heads')->insertOrIgnore([
                    'chain' => $chain, 'seq' => 0, 'hash' => AuditHasher::GENESIS, 'updated_at' => now(),
                ]);
                $head = DB::table('audit_chain_heads')->where('chain', $chain)->lockForUpdate()->first();

                $row['seq'] = (int) $head->seq + 1;
                $row['prev_hash'] = $head->hash;
                $row['hash'] = AuditHasher::hash($head->hash, $row);

                DB::table('audit_log')->insert($row);
                DB::table('audit_chain_heads')->where('chain', $chain)->update([
                    'seq' => $row['seq'], 'hash' => $row['hash'], 'updated_at' => now(),
                ]);
            });
        }, $this->tenant->entityId());

        return $row['id'];
    }

    private function entityIdFor(?Model $subject): ?string
    {
        if ($subject instanceof Entity) {
            return $subject->getKey();
        }

        // Models without an entity_id column (persons are shared across entities) go to the current entity's chain.
        if ($subject !== null && array_key_exists('entity_id', $subject->getAttributes())) {
            return $subject->getAttribute('entity_id') ?? $this->tenant->entityId();
        }

        return $this->tenant->entityId();
    }
}
