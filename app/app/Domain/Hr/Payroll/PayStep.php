<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

/** One part of the monthly pay calculation (base pay, overtime, a statutory deduction …). */
interface PayStep
{
    public function apply(PayContext $c): void;
}
