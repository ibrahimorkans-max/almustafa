<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\MfaService;
use App\Domain\Auth\SessionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\Entity;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emergency lockdown of the whole installation or of one firm. While a lock is active only Super
 * Admins (with 2FA) can sign in; everybody else in the scope is signed out everywhere and sees
 * "System locked by administrator". Data is not touched; offline devices keep queueing.
 */
final class Lockdown
{
    public const MESSAGE = 'System locked by administrator.';

    private const CACHE_KEY = 'security:locks';

    public function __construct(
        private readonly ActorContext $actor,
        private readonly MfaService $mfa,
        private readonly SessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly SecurityNotifier $notifier,
    ) {}

    /** @return object the new lock row */
    public function lock(?string $entityId, string $reason, string $code): object
    {
        $admin = $this->superAdminWithCode($code);
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for the lockdown; owners will see it.');
        }
        if ($this->isLocked($entityId) && ($entityId === null || $this->activeLock($entityId)?->entity_id === $entityId)) {
            throw new BusinessRuleViolation('This scope is already locked.');
        }
        $entityName = $entityId === null ? null
            : ($this->tenant->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('name')) ?? throw new BusinessRuleViolation('Unknown firm.'));

        $lock = DB::transaction(function () use ($admin, $entityId, $reason): object {
            $id = (string) Str::uuid7();
            DB::table('system_locks')->insert([
                'id' => $id, 'scope' => $entityId === null ? 'system' : 'entity', 'entity_id' => $entityId,
                'reason' => $reason, 'locked_by' => $admin->id, 'locked_at' => now()->utc()->format('Y-m-d H:i:s.v'),
            ]);
            Cache::forget(self::CACHE_KEY);
            $ended = $this->endSessionsInScope($entityId);
            $this->tenant->runAsSystem(fn () => $this->audit->record('security.lockdown.started', null, null,
                ['scope' => $entityId === null ? 'system' : 'entity', 'entity_id' => $entityId],
                ['reason' => $reason, 'lock_id' => $id, 'sessions_ended' => $ended], $entityId));

            return DB::table('system_locks')->where('id', $id)->first();
        });

        $where = $entityName ?? 'all firms';
        $this->notifier->owners($entityId, "Lockdown started ({$where})",
            "{$admin->username} locked {$where}. Reason: {$reason}. Everyone except Super Admins has been signed out until it is unlocked.");

        return $lock;
    }

    public function unlock(string $lockId, string $reason, string $code): void
    {
        $admin = $this->superAdminWithCode($code);
        $lock = DB::table('system_locks')->where('id', $lockId)->whereNull('unlocked_at')->first()
            ?? throw new BusinessRuleViolation('This lockdown is not active.');
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Say why it is safe to unlock.');
        }
        DB::table('system_locks')->where('id', $lockId)->update([
            'unlocked_by' => $admin->id, 'unlocked_at' => now()->utc()->format('Y-m-d H:i:s.v'), 'unlock_reason' => $reason,
        ]);
        Cache::forget(self::CACHE_KEY);
        $this->tenant->runAsSystem(fn () => $this->audit->record('security.lockdown.ended', null, null,
            ['scope' => $lock->scope, 'entity_id' => $lock->entity_id], ['reason' => $reason, 'lock_id' => $lockId], $lock->entity_id));
        $where = $lock->entity_id ? ($this->tenant->runAsSystem(fn () => Entity::query()->whereKey($lock->entity_id)->value('name')) ?? 'one firm') : 'all firms';
        $this->notifier->owners($lock->entity_id, "Lockdown ended ({$where})", "{$admin->username} unlocked {$where}. Reason: {$reason}. People can sign in again.");
    }

    /** Is this firm (or, with null, the whole installation) locked right now? */
    public function isLocked(?string $entityId): bool
    {
        return $this->activeLock($entityId) !== null;
    }

    public function activeLock(?string $entityId): ?object
    {
        foreach ($this->activeLocks() as $lock) {
            if ($lock->scope === 'system' || ($entityId !== null && $lock->entity_id === $entityId)) {
                return $lock;
            }
        }

        return null;
    }

    /** @return list<object> */
    public function activeLocks(): array
    {
        // Short cache: checked on every request; lock / unlock clear it at once on this server.
        return Cache::remember(self::CACHE_KEY, 30, fn () => DB::table('system_locks')->whereNull('unlocked_at')->orderBy('locked_at')->get()->all());
    }

    /** Applies to everybody except Super Admins. */
    public function blocks(LoginAccount $account): bool
    {
        return ! $account->isSuperAdmin() && $this->isLocked($account->entity_id);
    }

    private function superAdminWithCode(string $code): LoginAccount
    {
        $admin = $this->actor->account();
        if ($admin === null || ! $admin->isSuperAdmin()) {
            throw new BusinessRuleViolation('Only a Super Admin can lock or unlock the system.');
        }
        if (! $admin->hasMfa() || ! $this->mfa->verifyCode($admin, $code)) {
            $this->tenant->runAsSystem(fn () => $this->audit->record('security.lockdown.reauth_failed', $admin));

            throw new BusinessRuleViolation('The authentication code is not valid. Enter the current code from your authenticator app.');
        }

        return $admin;
    }

    private function endSessionsInScope(?string $entityId): int
    {
        return $this->tenant->runAsSystem(function () use ($entityId): int {
            $sessions = AuthSession::query()->whereNull('revoked_at')
                ->whereHas('loginAccount', fn ($q) => $q->whereHas('role', fn ($r) => $r->where('is_super_admin', false))
                    ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId)))
                ->get();
            $sessions->each(fn (AuthSession $s) => $this->sessions->revoke($s, 'lockdown'));

            return $sessions->count();
        });
    }
}
