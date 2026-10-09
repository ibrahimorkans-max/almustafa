<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Hr\Leave\LeaveService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Illuminate\Console\Command;

final class HrLeaveYearEndCommand extends Command
{
    protected $signature = 'hr:leave-year-end {year : The calendar year that ended}';

    protected $description = 'Carries leave balances forward (up to each type\'s limit) and lapses the rest; safe to run twice';

    public function handle(TenantContext $tenant): int
    {
        $year = (int) $this->argument('year');
        if ($year < 2000 || $year >= (int) now('Asia/Karachi')->year) {
            $this->error('Run it for a year that has ended.');

            return self::FAILURE;
        }
        foreach ($tenant->runAsSystem(fn () => Entity::query()->get()) as $entity) {
            $n = $tenant->runInEntity($entity->id, fn () => app(LeaveService::class)->yearEnd($year));
            $this->line("{$entity->code}: {$n} ledger entr".($n === 1 ? 'y' : 'ies'));
        }

        return self::SUCCESS;
    }
}
