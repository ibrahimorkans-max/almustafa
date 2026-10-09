<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Hr\EmployeeDocuments;
use App\Domain\Security\Lockdown;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

final class HrDocumentExpiryCommand extends Command
{
    protected $signature = 'hr:document-expiry';

    protected $description = 'Alerts HR about employee documents expiring in 30, 7 or 0 days';

    public function handle(TenantContext $tenant): int
    {
        $total = 0;
        foreach ($tenant->runAsSystem(fn () => Entity::query()->where('status', Entity::STATUS_ACTIVE)->get()) as $entity) {
            if (app(Lockdown::class)->isLocked($entity->id)) {
                $this->warn("{$entity->code}: skipped, locked by administrator");

                continue;
            }
            $total += $tenant->runInEntity($entity->id, fn () => app(EmployeeDocuments::class)->remindExpiries());
        }
        $this->info("{$total} expiry reminder(s) raised.");

        return self::SUCCESS;
    }
}
