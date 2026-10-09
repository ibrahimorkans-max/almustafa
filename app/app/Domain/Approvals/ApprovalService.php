<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Approvals\Actions\ChangeRolePermissions;
use App\Domain\Approvals\Actions\CreditOverride;
use App\Domain\Approvals\Actions\ManualWeighmentOverride;
use App\Domain\Approvals\Actions\PartyBankAccountChange;
use App\Domain\Approvals\Actions\PaymentRelease;
use App\Domain\Approvals\Actions\PeriodUnlock;
use App\Domain\Approvals\Actions\RateChange;
use App\Domain\Approvals\Actions\StockAdjustment;
use App\Domain\Approvals\Actions\TrustDevice;
use App\Domain\Approvals\Actions\VoucherReversal;
use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Contracts\ExplainsCheckerRule;
use App\Domain\Approvals\Contracts\HandlesRejection;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Filament\Resources\ApprovalRequestResource;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Maker-checker. Rules enforced here (not in the UI):
 *  - the maker needs the action's maker permission;
 *  - the checker needs `approval.decide`, must be a different PERSON than the maker
 *    (a second login account of the same person does not count), and must pass
 *    the action's own checker rule;
 *  - a request is decided once; expired requests cannot be approved;
 *  - the approved change runs in the same transaction as the status change.
 */
final class ApprovalService
{
    /** @var array<string, class-string<ApprovalAction>> */
    private const ACTIONS = [
        ChangeRolePermissions::KEY => ChangeRolePermissions::class,
        TrustDevice::KEY => TrustDevice::class,
        PartyBankAccountChange::KEY => PartyBankAccountChange::class,
        ManualWeighmentOverride::KEY => ManualWeighmentOverride::class,
        StockAdjustment::KEY => StockAdjustment::class,
        PaymentRelease::KEY => PaymentRelease::class,
        VoucherReversal::KEY => VoucherReversal::class,
        PeriodUnlock::KEY => PeriodUnlock::class,
        CreditOverride::KEY => CreditOverride::class,
        RateChange::KEY => RateChange::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly AuditLogger $audit,
        private readonly ActorContext $actor,
    ) {}

    public function action(string $key): ApprovalAction
    {
        $class = self::ACTIONS[$key] ?? throw new ApprovalDenied("Unknown approval action {$key}.");

        return $this->container->make($class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(string $actionKey, array $payload, string $summary, ?string $reason = null): ApprovalRequest
    {
        $maker = $this->actor->account() ?? throw new ApprovalDenied('Not signed in.');

        return $this->submitFor($maker, $actionKey, $payload, $summary, $reason);
    }

    /**
     * Submit on behalf of an account. $systemInitiated is for requests the system raises itself
     * (e.g. the untrusted-device check during login), where the maker's permission is not the point.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submitFor(
        LoginAccount $maker,
        string $actionKey,
        array $payload,
        string $summary,
        ?string $reason = null,
        bool $systemInitiated = false,
    ): ApprovalRequest {
        $action = $this->action($actionKey);

        if (! $systemInitiated && ! $maker->hasPermission($action->makerPermission())) {
            throw new ApprovalDenied("You need '{$action->makerPermission()}' to request this.");
        }

        $payload = $action->validate($payload, $maker);

        return DB::transaction(function () use ($maker, $actionKey, $payload, $summary, $reason): ApprovalRequest {
            $request = ApprovalRequest::query()->create([
                'entity_id' => $maker->entity_id,
                'action' => $actionKey,
                'payload' => $payload,
                'summary' => mb_substr($summary, 0, 255),
                'reason' => $reason,
                'maker_login_account_id' => $maker->id,
                'maker_person_id' => $maker->person_id,
                'expires_at' => now()->addHours((int) config('ricemill.approvals.expire_after_hours')),
            ]);
            $this->audit->record('approval.submitted', $request, null, ['action' => $actionKey, 'payload' => $payload]);

            return $request;
        });
    }

    public function canDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $this->decisionBlocker($checker, $request) === null;
    }

    /**
     * Why this account may not approve / reject the request, in words for the screen; null = it may.
     * The same rules are enforced on approve() and reject(); they apply to Super Admins too.
     */
    public function decisionBlocker(LoginAccount $checker, ApprovalRequest $request): ?string
    {
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            return "This request is already {$request->status}; decided requests cannot be changed.";
        }
        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            return 'This request has expired; ask for it to be raised again.';
        }
        if ($checker->person_id === $request->maker_person_id) {
            return 'You cannot approve your own request. Another person with approval rights must decide it (maker-checker).';
        }
        if ($checker->hasPendingSecuritySetup()) {
            return 'Finish your account setup first (password change / two-factor sign-in); until then your account has no permissions.';
        }
        if (! $checker->hasPermission('approval.decide')) {
            return "Approving needs the 'approval.decide' permission, which your role does not have.";
        }
        $action = $this->action($request->action);
        if (! $action->checkerMayDecide($checker, $request)) {
            return $action instanceof ExplainsCheckerRule
                ? $action->whyCheckerMayNotDecide($checker, $request)
                : 'Your role is not allowed to decide this kind of request.';
        }

        return null;
    }

    public function approve(ApprovalRequest $request, LoginAccount $checker, ?string $note = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $checker, $note): ApprovalRequest {
            $request = $this->lockPending($request);
            $this->assertCanDecide($checker, $request);

            $this->decide($request, $checker, $note);
            $this->audit->record('approval.approved', $request, meta: ['note' => $note]);

            try {
                // Savepoint: a failing action rolls back its own changes but the decision is kept.
                $result = DB::transaction(fn () => $this->action($request->action)->execute($request, $checker));
                $request->status = ApprovalRequest::STATUS_EXECUTED;
                $request->executed_at = now();
                $request->result = $result;
            } catch (ApprovalDenied $e) {
                $request->status = ApprovalRequest::STATUS_FAILED;
                $request->error = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $request->status = ApprovalRequest::STATUS_FAILED;
                $request->error = 'Execution failed: '.class_basename($e);
            }

            $request->save();
            if ($request->status === ApprovalRequest::STATUS_FAILED) {
                $this->notifyClosed($request);
            }
            $this->audit->record('approval.'.$request->status, $request, meta: ['result' => $request->result, 'error' => $request->error]);
            $this->notifyRequester($request);

            return $request;
        });
    }

    public function reject(ApprovalRequest $request, LoginAccount $checker, string $note): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $checker, $note): ApprovalRequest {
            $request = $this->lockPending($request);
            $this->assertCanDecide($checker, $request);

            $this->decide($request, $checker, $note);
            $request->status = ApprovalRequest::STATUS_REJECTED;
            $request->save();
            $this->notifyClosed($request);
            $this->audit->record('approval.rejected', $request, meta: ['note' => $note]);
            $this->notifyRequester($request);

            return $request;
        });
    }

    /** The maker may withdraw their own pending request. */
    public function cancel(ApprovalRequest $request, LoginAccount $maker): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $maker): ApprovalRequest {
            $request = $this->lockPending($request);
            if ($request->maker_person_id !== $maker->person_id) {
                throw new ApprovalDenied('Only the requester can cancel this request.');
            }
            $request->status = ApprovalRequest::STATUS_CANCELLED;
            $request->save();
            $this->notifyClosed($request);
            $this->audit->record('approval.cancelled', $request);

            return $request;
        });
    }

    private function notifyClosed(ApprovalRequest $request): void
    {
        $action = $this->action($request->action);
        if ($action instanceof HandlesRejection) {
            $action->closed($request);
        }
    }

    private function lockPending(ApprovalRequest $request): ApprovalRequest
    {
        /** @var ApprovalRequest $fresh */
        $fresh = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        if (! $fresh->isPending()) {
            throw new ApprovalDenied('This request is no longer pending.');
        }

        return $fresh;
    }

    private function assertCanDecide(LoginAccount $checker, ApprovalRequest $request): void
    {
        $blocker = $this->decisionBlocker($checker, $request);
        if ($blocker !== null) {
            throw new ApprovalDenied($blocker);
        }
    }

    /** Tells the requester (every login of that person in the request's firm) how it ended. */
    private function notifyRequester(ApprovalRequest $request): void
    {
        $recipients = LoginAccount::query()->where('person_id', $request->maker_person_id)
            ->where('status', LoginAccount::STATUS_ACTIVE)->get();
        if ($recipients->isEmpty()) {
            return;
        }
        $by = $request->checker_login_account_id ? LoginAccount::query()->whereKey($request->checker_login_account_id)->value('username') : null;
        $n = Notification::make()
            ->title("Your request was {$request->status}: ".mb_substr($request->summary, 0, 120))
            ->body(trim(($by ? "Decided by {$by}. " : '').($request->decision_note ?? '').($request->error ? " Error: {$request->error}" : '')))
            ->actions([NotificationAction::make('open')->label('Open')->url(ApprovalRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))]);
        (in_array($request->status, [ApprovalRequest::STATUS_EXECUTED], true) ? $n->success() : $n->danger())->sendToDatabase($recipients);
    }

    private function decide(ApprovalRequest $request, LoginAccount $checker, ?string $note): void
    {
        $request->checker_login_account_id = $checker->id;
        $request->checker_person_id = $checker->person_id;
        $request->decided_at = now();
        $request->decision_note = $note;
    }
}
