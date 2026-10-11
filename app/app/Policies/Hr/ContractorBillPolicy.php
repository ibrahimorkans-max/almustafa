<?php

declare(strict_types=1);

namespace App\Policies\Hr;

/** Contractor bills: prepared by payroll, approved by its approvers. */
final class ContractorBillPolicy extends ReimbursementPolicy
{
    protected array $viewers = ['hr.payroll.run', 'hr.payroll.approve'];

    protected array $creators = ['hr.payroll.run'];
}
