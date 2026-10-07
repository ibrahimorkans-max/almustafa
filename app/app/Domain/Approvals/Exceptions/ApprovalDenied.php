<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Exceptions;

use RuntimeException;

final class ApprovalDenied extends RuntimeException {}
