<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Saas\Billing;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily: invoice reminders (3 days before the due date, on it, once overdue); read-only for a customer
 * still unpaid past the grace days; paused past SAAS_SUSPEND_AFTER_DAYS when that is set.
 */
final class SaasBillingCheckCommand extends Command
{
    protected $signature = 'saas:billing-check';

    protected $description = 'Remind customers of unpaid invoices and make overdue ones read-only';

    public function handle(TenantContext $tenant, Billing $billing): int
    {
        $lines = $tenant->runAsSystem(fn () => $billing->check());
        foreach ($lines as $line) {
            $this->line($line);
        }
        $this->info(count($lines).' changes.');

        return self::SUCCESS;
    }
}
