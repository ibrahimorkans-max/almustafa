<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use RuntimeException;

/** A request that is well-formed but not allowed by the business rules (shown to the user as-is). */
final class BusinessRuleViolation extends RuntimeException {}
