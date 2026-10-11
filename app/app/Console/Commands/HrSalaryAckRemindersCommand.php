<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Hr\Payroll\SalaryPaymentService;
use App\Domain\Security\Lockdown;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

final class HrSalaryAckRemindersCommand extends Command
{
    protected $signature = 'hr:salary-ack-reminders';

    protected $description = 'Alerts HR about cash salary payments still without the employee\'s signature or thumb';

    public function handle(TenantContext $tenant): int
    {
        $total = 0;
        foreach ($tenant->runAsSystem(fn () => Entity::query()->where('status', Entity::STATUS_ACTIVE)->get()) as $entity) {
            if (app(Lockdown::class)->isLocked($entity->id)) {
                $this->warn("{$entity->code}: skipped, locked by administrator");

                continue;
            }
            $total += $tenant->runInEntity($entity->id, fn () => app(SalaryPaymentService::class)->remindUnacknowledged());
        }
        $this->info("{$total} reminder(s) raised.");

        return self::SUCCESS;
    }
}
