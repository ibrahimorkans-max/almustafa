<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\SecurityNotifier;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\SignInApproval;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The last check of a new user's first sign-in: somebody already signed in who may manage devices (an Owner or the
 * mill's administrator) approves it. The new screen shows a QR code and a number; the approver scans the code (or
 * opens Approve sign-ins), sees who is signing in and from where, and picks the same number out of three. A wrong
 * number or "Not me" refuses it and ends that sign-in. When nobody else could approve (a new company's first
 * Owner) the check is skipped, and that is recorded.
 */
final class SignInApprovals
{
    public const RIGHT = 'device.manage';

    private const MINUTES = 10;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ricemill.auth.first_sign_in_approval', true);
    }

    /**
     * Who may approve this person's sign-in: other active people of the same company (or mill, without one) who
     * may manage devices and have finished their own first sign-in.
     *
     * @return Collection<int, LoginAccount>
     */
    public function approvers(LoginAccount $account): Collection
    {
        $customer = $account->customerId();

        return $this->tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->with(['role', 'entity'])
            ->where('status', LoginAccount::STATUS_ACTIVE)->whereKeyNot($account->id)->where('is_machine', false)
            ->whereNotNull('first_sign_in_completed_at')
            ->when($customer !== null,
                fn ($q) => $q->whereIn('entity_id', Entity::query()->withoutGlobalScopes()->where('customer_id', $customer)->select('id')),
                fn ($q) => $q->where('entity_id', $account->entity_id))
            ->get()
            // The SaaS owner's own sign-ins are approved by another SaaS owner; a company's, by its own people.
            ->filter(fn (LoginAccount $a) => $a->isPlatformAdmin() === $account->isPlatformAdmin() && in_array(self::RIGHT, $a->permissionCodes(), true))
            ->values());
    }

    /** Whether this person's first sign-in still waits for an approval. */
    public function needed(LoginAccount $account): bool
    {
        return $this->enabled() && $account->first_sign_in_approved_at === null && $this->approvers($account)->isNotEmpty();
    }

    /**
     * A new request for this sign-in (an older open one is withdrawn) and the token of its QR code. The approvers
     * are told. `$purpose`: a first sign-in, or a sign-in from a new network (NewNetwork, without an e-mail on the login).
     *
     * @return array{approval: SignInApproval, token: string}
     */
    public function open(LoginAccount $account, ?AuthSession $session, string $purpose = EmailCode::FIRST_SIGN_IN): array
    {
        $token = Str::random(40);
        $number = random_int(10, 99);
        $choices = [$number];
        while (count($choices) < 3) {
            $other = random_int(10, 99);
            if (! in_array($other, $choices, true)) {
                $choices[] = $other;
            }
        }
        shuffle($choices);

        $wasOpen = false;
        $approval = $this->tenant->runAsSystem(function () use ($account, $session, $token, $number, $choices, $purpose, &$wasOpen): SignInApproval {
            $open = SignInApproval::query()->withoutGlobalScopes()->where('login_account_id', $account->id)->where('purpose', $purpose)->where('status', SignInApproval::PENDING);
            $wasOpen = (clone $open)->where('expires_at', '>', now())->exists();
            $open->update(['status' => SignInApproval::DENIED, 'reason' => 'replaced', 'decided_at' => now()]);

            return SignInApproval::query()->withoutGlobalScopes()->create([
                'entity_id' => $account->entity_id, 'login_account_id' => $account->id, 'auth_session_id' => $session?->id, 'purpose' => $purpose,
                'token_hash' => hash('sha256', $token), 'number' => $number, 'choices' => $choices,
                'status' => SignInApproval::PENDING, 'expires_at' => now()->addMinutes(self::MINUTES), 'created_at' => now(),
            ]);
        });
        $this->audit->record('auth.sign_in_approval.requested', $account, meta: ['approval_id' => $approval->id, 'purpose' => $purpose, 'ip' => $session?->ip]);
        if ($wasOpen) {
            return ['approval' => $approval, 'token' => $token];   // the screen was opened again: already told
        }
        $network = $purpose === EmailCode::NEW_NETWORK;
        app(SecurityNotifier::class)->owners($account->entity_id, ($network ? 'Approve a sign-in from a new network: ' : 'Approve a first sign-in: ').$account->username,
            $account->username.($network ? ' is signing in from a new network' : ' is signing in for the first time').($session?->ip ? " from {$session->ip}" : '')
            .'. If you expect it, open Security → Approve sign-ins (or scan the QR code on their screen) and pick the number their screen shows.');

        return ['approval' => $approval, 'token' => $token];
    }

    public function find(string $id): ?SignInApproval
    {
        return $this->tenant->runAsSystem(fn () => SignInApproval::query()->withoutGlobalScopes()->with('loginAccount.person', 'loginAccount.role', 'loginAccount.entity')->find($id));
    }

    public function findByToken(string $token): ?SignInApproval
    {
        return $this->tenant->runAsSystem(fn () => SignInApproval::query()->withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))->first());
    }

    /**
     * The open requests this person may decide.
     *
     * @return Collection<int, SignInApproval>
     */
    public function pendingFor(LoginAccount $approver): Collection
    {
        return $this->tenant->runAsSystem(fn () => SignInApproval::query()->withoutGlobalScopes()
            ->with('loginAccount.person', 'loginAccount.role', 'loginAccount.entity')
            ->where('status', SignInApproval::PENDING)->where('expires_at', '>', now())->latest('created_at')->get())
            ->filter(fn (SignInApproval $a) => $a->loginAccount !== null && $this->mayDecide($approver, $a))->values();
    }

    public function mayDecide(LoginAccount $approver, SignInApproval $approval): bool
    {
        $requester = $this->tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()->with('role')->find($approval->login_account_id));

        return $requester !== null && $this->approvers($requester)->contains(fn (LoginAccount $a) => $a->id === $approver->id);
    }

    /**
     * The approver picked a number: the right one approves the sign-in; a wrong one refuses it and ends it.
     *
     * @return bool approved
     */
    public function pick(LoginAccount $approver, string $approvalId, int $picked): bool
    {
        $approval = $this->decidable($approver, $approvalId);
        if ($picked !== $approval->number) {
            $this->refuse($approver, $approval, 'wrong_number');

            return false;
        }
        $this->tenant->runAsSystem(function () use ($approval, $approver): void {
            SignInApproval::query()->withoutGlobalScopes()->whereKey($approval->id)
                ->update(['status' => SignInApproval::APPROVED, 'decided_by' => $approver->id, 'decided_at' => now()]);
            if ($approval->purpose !== EmailCode::NEW_NETWORK) {
                LoginAccount::query()->withoutGlobalScopes()->whereKey($approval->login_account_id)->toBase()->update(['first_sign_in_approved_at' => now()]);
            }
        });
        $this->audit->record('auth.sign_in_approval.approved', $approval->loginAccount, meta: ['approval_id' => $approval->id, 'purpose' => $approval->purpose, 'by' => $approver->username]);
        // A new network: it is added and the held sign-in goes on.
        $session = $approval->auth_session_id !== null ? $this->tenant->runAsSystem(fn () => AuthSession::query()->withoutGlobalScopes()->find($approval->auth_session_id)) : null;
        if ($approval->purpose === EmailCode::NEW_NETWORK && $session !== null) {
            app(NewNetwork::class)->allow($approval->loginAccount, $session, $session->ip, 'approved by '.$approver->username);
        }

        return true;
    }

    /** "Not me / not expected": refused, and that sign-in ends. */
    public function deny(LoginAccount $approver, string $approvalId): void
    {
        $this->refuse($approver, $this->decidable($approver, $approvalId), 'denied');
    }

    /** Where a request stands, for the new screen (asked every few seconds). */
    public function status(string $approvalId): ?string
    {
        $approval = $this->find($approvalId);

        return match (true) {
            $approval === null => null,
            $approval->status === SignInApproval::PENDING && ! $approval->expires_at->isFuture() => 'expired',
            default => $approval->status,
        };
    }

    private function decidable(LoginAccount $approver, string $approvalId): SignInApproval
    {
        $approval = $this->find($approvalId);
        if ($approval === null || ! $approval->isOpen()) {
            throw new BusinessRuleViolation('This sign-in is no longer waiting (approved, refused or too old).');
        }
        if (! $this->mayDecide($approver, $approval)) {
            throw new BusinessRuleViolation('You may not approve this sign-in.');
        }

        return $approval;
    }

    private function refuse(LoginAccount $approver, SignInApproval $approval, string $reason): void
    {
        $this->tenant->runAsSystem(function () use ($approval, $approver, $reason): void {
            SignInApproval::query()->withoutGlobalScopes()->whereKey($approval->id)
                ->update(['status' => SignInApproval::DENIED, 'reason' => $reason, 'decided_by' => $approver->id, 'decided_at' => now()]);
            $session = $approval->auth_session_id !== null ? AuthSession::query()->withoutGlobalScopes()->find($approval->auth_session_id) : null;
            if ($session !== null && $session->revoked_at === null) {
                app(SessionService::class)->revoke($session, $approval->purpose.'_'.$reason);
            }
        });
        $this->audit->record('auth.sign_in_approval.refused', $approval->loginAccount, meta: ['approval_id' => $approval->id, 'purpose' => $approval->purpose, 'by' => $approver->username, 'reason' => $reason]);
        app(SecurityNotifier::class)->owners($approval->entity_id, ($approval->purpose === EmailCode::NEW_NETWORK ? 'Sign-in from a new network refused: ' : 'First sign-in refused: ').$approval->loginAccount->username,
            ($reason === 'wrong_number' ? 'The wrong number was picked' : "{$approver->username} said it was not expected")
            .": that sign-in was ended. If it was not the person, change the login's password.");
    }
}
