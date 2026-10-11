<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounts\Expenses;
use App\Domain\Security\Lockdown;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

final class ExpensesRecurringCommand extends Command
{
    protected $signature = 'expenses:recurring';

    protected $description = 'Drafts the recurring expenses that are due (rent, electricity …) for accounts to check and post';

    public function handle(TenantContext $tenant): int
    {
        $total = 0;
        foreach ($tenant->runAsSystem(fn () => Entity::query()->where('status', Entity::STATUS_ACTIVE)->get()) as $entity) {
            if (app(Lockdown::class)->isLocked($entity->id)) {
                $this->warn("{$entity->code}: skipped, locked by administrator");

                continue;
            }
            $total += $tenant->runInEntity($entity->id, fn () => app(Expenses::class)->draftRecurring());
        }
        $this->info("{$total} draft(s) made.");

        return self::SUCCESS;
    }
}
