<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Contracts;

use App\Models\ApprovalRequest;
use App\Models\LoginAccount;
use Illuminate\Validation\ValidationException;

/**
 * One kind of action that needs maker-checker approval.
 * Later phases add payments, rate changes, stock adjustments, voucher reversals
 * and manual weight overrides by implementing this interface.
 */
interface ApprovalAction
{
    public function key(): string;

    /** Permission the maker needs to submit the request. */
    public function makerPermission(): string;

    /** Extra rule for the checker beyond `approval.decide` + different person. */
    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool;

    /**
     * Validates and normalises the payload when the request is submitted.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $payload, LoginAccount $maker): array;

    /**
     * Performs the approved change. Runs inside the approval transaction.
     *
     * @return array<string, mixed> result stored on the request
     */
    public function execute(ApprovalRequest $request, LoginAccount $checker): array;
}
