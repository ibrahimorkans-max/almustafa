<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Procurement\QuoteService;
use App\Domain\Procurement\SaudaService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

/** Daily: expires quotes past validity and saudas past their delivery period, per entity. */
final class ProcurementExpireCommand extends Command
{
    protected $signature = 'procurement:expire';

    protected $description = 'Expire overdue quotes and saudas (records remaining quantity)';

    public function handle(TenantContext $tenant): int
    {
        $entities = $tenant->runAsSystem(fn () => Entity::query()->pluck('id'));
        foreach ($entities as $entityId) {
            $tenant->runAsSystem(function () {
                $quotes = app(QuoteService::class)->expireOverdue();
                $saudas = app(SaudaService::class)->expireOverdue();
                $this->line("quotes: {$quotes}, saudas: {$saudas}");
            }, $entityId);
        }

        return self::SUCCESS;
    }
}
