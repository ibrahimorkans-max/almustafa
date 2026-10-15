<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;

/**
 * Suspend (reversible), revoke (permanent) and reinstate login accounts. Withdrawing access ends
 * every web session and API token of the account at once — phones, PWA and gate agents included:
 * their next request or sync is refused with the reason `access_disabled`.
 */
final class AccountStatusService
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly SessionService $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function suspend(LoginAccount $account, string $reason): LoginAccount
    {
        return $this->change($account, LoginAccount::STATUS_SUSPENDED, $reason);
    }

    public function revoke(LoginAccount $account, string $reason): LoginAccount
    {
        return $this->change($account, LoginAccount::STATUS_REVOKED, $reason);
    }

    public function reinstate(LoginAccount $account, string $reason): LoginAccount
    {
        if ($account->status === LoginAccount::STATUS_REVOKED) {
            throw new BusinessRuleViolation('A revoked account is closed for good. Create a new account if the person needs access again.');
        }

        return $this->change($account, LoginAccount::STATUS_ACTIVE, $reason);
    }

    private function change(LoginAccount $account, string $status, string $reason): LoginAccount
    {
        $actor = $this->actor->account() ?? throw new BusinessRuleViolation('Not signed in.');
        if (! $actor->hasPermission('user.manage')) {
            throw new BusinessRuleViolation("Changing account access needs the 'user.manage' permission.");
        }
        if ($actor->id === $account->id || $actor->person_id === $account->person_id) {
            throw new BusinessRuleViolation('You cannot change the access of your own account.');
        }
        if ($account->isOwner() && ! $actor->isOwner()) {
            throw new BusinessRuleViolation('Only an Owner can change the access of an Owner account.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give the reason (at least a few words); it is kept in the audit log.');
        }
        if ($account->status === $status) {
            return $account;
        }

        return DB::transaction(function () use ($account, $status, $reason): LoginAccount {
            $before = $account->status;
            $account->status = $status;
            $account->save();
            $ended = $status === LoginAccount::STATUS_ACTIVE ? 0 : $this->sessions->revokeAllFor($account, 'account_'.$status);
            $this->audit->record('auth.account.'.$status, $account, ['status' => $before], ['status' => $status],
                ['reason' => $reason, 'sessions_ended' => $ended]);

            return $account;
        });
    }
}
