<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Approvals\ApprovalService;
use App\Models\ApprovalRequest;
use App\Models\LoginAccount;

final class ApprovalRequestPolicy
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function viewAny(LoginAccount $user): bool
    {
        return $user->hasPermission('approval.view') || $user->hasPermission('approval.decide');
    }

    public function view(LoginAccount $user, ApprovalRequest $request): bool
    {
        return $this->viewAny($user) || $request->maker_person_id === $user->person_id;
    }

    public function decide(LoginAccount $user, ApprovalRequest $request): bool
    {
        return $this->approvals->canDecide($user, $request);
    }

    public function cancel(LoginAccount $user, ApprovalRequest $request): bool
    {
        return $request->isPending() && $request->maker_person_id === $user->person_id;
    }

    public function create(LoginAccount $user): bool
    {
        return false;
    }

    public function update(LoginAccount $user, ApprovalRequest $request): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, ApprovalRequest $request): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}
