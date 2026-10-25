<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Access\RoleTemplates;
use App\Models\LoginAccount;

/**
 * The main users, who do not need a second person: the Owner, and in each mill the Super Admin, the
 * Finance Manager, the Purchase Manager and the Accountant. What they request is applied at once when
 * they also hold the right to decide it, and they may approve what they created themselves (payments,
 * requests, saudas, prices and Caat settlements, claims, bills, payroll runs, requisitions, weight
 * checks). Every such decision is still audited as theirs, with the note that no second person was needed.
 *
 * Not covered, on purpose: approving a new sign-in device or an attendance device (that is the
 * security check itself), and approving money or leave for oneself as an employee (own advance, pay
 * adjustment, reimbursement, leave, attendance).
 */
final class SelfApproval
{
    /** Mill roles that approve their own work (the Owner always does). */
    public const ROLES = [
        RoleTemplates::SUPER_ADMIN, RoleTemplates::FINANCE_MANAGER, RoleTemplates::PURCHASE_MANAGER, RoleTemplates::ACCOUNTANT,
    ];

    public static function allowed(?LoginAccount $account): bool
    {
        return $account !== null && ($account->isOwner() || in_array($account->role?->code, self::ROLES, true));
    }

    /** The note kept on a request applied without a second person. */
    public static function note(LoginAccount $account): string
    {
        $role = $account->isOwner() ? 'Owner' : ($account->role->name ?? 'main user');

        return 'Applied directly: requested by the '.$role.' (no second approval needed).';
    }
}
