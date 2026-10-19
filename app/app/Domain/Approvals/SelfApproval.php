<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Access\RoleTemplates;
use App\Models\LoginAccount;

/**
 * Who does not need a second person: the Owner and each mill's Super Admin. Their own requests are
 * applied at once, and they may approve what they created themselves (requests, saudas, claims,
 * bills, payroll runs, weight checks). Every such decision is still audited as theirs.
 *
 * Not covered, on purpose: approving a new sign-in device (that is the sign-in security check
 * itself), and approving money or leave for oneself as an employee (own advance, pay adjustment,
 * reimbursement, leave, attendance).
 */
final class SelfApproval
{
    public static function allowed(?LoginAccount $account): bool
    {
        return $account !== null && ($account->isOwner() || $account->role?->code === RoleTemplates::SUPER_ADMIN);
    }

    /** The note kept on a request applied without a second person. */
    public static function note(LoginAccount $account): string
    {
        return 'Applied directly: requested by the '.($account->isOwner() ? 'Owner' : 'Super Admin').' (no second approval needed).';
    }
}
