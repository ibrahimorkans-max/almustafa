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
        // Whether this person may lock this scope at all — before the one-time code is used up.
        $this->assertScope($this->actor->account() ?? throw new BusinessRuleViolation('Only an Owner can lock or unlock the system.'), $entityId);
        $admin = $this->ownerWithCode($code);
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason for the lockdown; the Owner and Super Admins will see it.');
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
            "{$admin->username} locked {$where}. Reason: {$reason}. Everyone except Owners has been signed out until it is unlocked.");

        return $lock;
    }

    public function unlock(string $lockId, string $reason, string $code): void
    {
        $lock = DB::table('system_locks')->where('id', $lockId)->whereNull('unlocked_at')->first()
            ?? throw new BusinessRuleViolation('This lockdown is not active.');
        $this->assertScope($this->actor->account() ?? throw new BusinessRuleViolation('Only an Owner can lock or unlock the system.'), $lock->entity_id);
        $admin = $this->ownerWithCode($code);
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

    /**
     * A whole-system lock (the platform's) applies to everybody except the platform admins; a mill's lock to
     * everybody in that mill except the Owners.
     */
    public function blocks(LoginAccount $account): bool
    {
        if ($account->isPlatformAdmin()) {
            return false;
        }
        $lock = $this->activeLock($account->entity_id);

        return $lock !== null && ($lock->scope === 'system' || ! $account->isOwner());
    }

    /** The whole system is the platform admin's; a customer's Owner locks and unlocks its own mills only. */
    private function assertScope(LoginAccount $admin, ?string $entityId): void
    {
        if ($admin->isPlatformAdmin()) {
            return;
        }
        if ($entityId === null) {
            throw new BusinessRuleViolation('Only the platform admin can lock the whole system. Lock one of your mills instead.');
        }
        $customer = $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('customer_id'));
        if ($customer === null || $customer !== $admin->customerId()) {
            throw new BusinessRuleViolation('You can lock only your own mills.');
        }
    }

    private function ownerWithCode(string $code): LoginAccount
    {
        $admin = $this->actor->account();
        if ($admin === null || ! $admin->isOwner()) {
            throw new BusinessRuleViolation('Only an Owner can lock or unlock the system.');
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
                ->whereHas('loginAccount', fn ($q) => $entityId === null
                    // The whole system: everyone but the platform admins.
                    ? $q->where('is_platform_admin', false)
                    // One mill: everyone in it but the Owners.
                    : $q->whereHas('role', fn ($r) => $r->where('is_super_admin', false))->where('entity_id', $entityId))
                ->get();
            $sessions->each(fn (AuthSession $s) => $this->sessions->revoke($s, 'lockdown'));

            return $sessions->count();
        });
    }
}
