<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Contracts;

use App\Models\ApprovalRequest;

/**
 * Optional for an ApprovalAction whose subject has its own status that must follow the
 * request when it is rejected, withdrawn or fails on execution (e.g. a stock count waiting for adjustment).
 */
interface HandlesRejection
{
    /** Runs inside the decision transaction; $request->status is already final. */
    public function closed(ApprovalRequest $request): void;
}
