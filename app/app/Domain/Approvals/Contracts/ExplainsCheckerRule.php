<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Contracts;

use App\Models\ApprovalRequest;
use App\Models\LoginAccount;

/** An action whose own checker rule can say, in plain words, why this checker may not decide. */
interface ExplainsCheckerRule
{
    public function whyCheckerMayNotDecide(LoginAccount $checker, ApprovalRequest $request): string;
}
