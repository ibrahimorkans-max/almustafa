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
use App\Domain\Approvals\Contracts\HandlesRejection;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
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
        return $request->isPending()
            && $checker->hasPermission('approval.decide')
            && $checker->person_id !== $request->maker_person_id
            && $this->action($request->action)->checkerMayDecide($checker, $request);
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
        if (! $checker->hasPermission('approval.decide')) {
            throw new ApprovalDenied("You need 'approval.decide' to approve or reject requests.");
        }
        if ($checker->person_id === $request->maker_person_id) {
            throw new ApprovalDenied('The requester cannot decide their own request (maker-checker).');
        }
        if (! $this->action($request->action)->checkerMayDecide($checker, $request)) {
            throw new ApprovalDenied('You are not allowed to decide this kind of request.');
        }
    }

    private function decide(ApprovalRequest $request, LoginAccount $checker, ?string $note): void
    {
        $request->checker_login_account_id = $checker->id;
        $request->checker_person_id = $checker->person_id;
        $request->decided_at = now();
        $request->decision_note = $note;
    }
}
