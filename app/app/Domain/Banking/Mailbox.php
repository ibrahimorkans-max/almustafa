<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Models\Banking\EmailConnection;
use Carbon\CarbonImmutable;

/** Reads the e-mails of a connected mailbox (read-only: nothing is moved, marked or deleted). */
interface Mailbox
{
    /**
     * E-mails received since the given moment (newest first is fine), at most $limit.
     *
     * @return list<MailMessage>
     */
    public function fetch(EmailConnection $connection, CarbonImmutable $since, int $limit = 100): array;
}
