<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Saas\Billing;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Monthly (the 1st): this month's invoice for every active or payment-due customer on a paid plan.
 * Running it again issues nothing twice.
 */
final class SaasInvoicesCommand extends Command
{
    protected $signature = 'saas:invoices {--month= : YYYY-MM (default: this month, Pakistan time)}';

    protected $description = 'Issue the month\'s subscription invoices to customers';

    public function handle(TenantContext $tenant, Billing $billing): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('month').'-01', 'Asia/Karachi')
            : CarbonImmutable::now('Asia/Karachi');
        if (! $month instanceof CarbonImmutable) {
            $this->error('Give the month as YYYY-MM.');

            return self::FAILURE;
        }

        return $tenant->runAsSystem(function () use ($billing, $month): int {
            $issued = 0;
            foreach (Customer::query()->orderBy('code')->get() as $customer) {
                $invoice = $billing->issueMonthly($customer, $month);
                if ($invoice !== null) {
                    $issued++;
                    $this->line("{$customer->code}: {$invoice->number} issued.");
                }
            }
            $this->info("{$issued} invoices issued for ".$month->format('F Y').'.');

            return self::SUCCESS;
        });
    }
}
