<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\LiveWeight;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Gate\Scale;
use App\Models\LoginAccount;
use Illuminate\Console\Command;

/**
 * Pretends to be the gate agent on the weighbridge PC (local/demo only): sends a reading every
 * second, as a truck drives on (Moving), stands still (Stable) and drives off, again and again.
 * Keep it running in a terminal, open Live weight, choose the waiting vehicle and press Record.
 */
final class DemoLiveWeightCommand extends Command
{
    protected $signature = 'ricemill:demo-live-weight
        {--kg=24560 : Weight of the pretend truck}
        {--seconds=900 : How long to run}
        {--entity=AMR : Mill code}';

    protected $description = 'Demo only: send pretend weighbridge readings to the Live weight screen';

    public function handle(TenantContext $tenant, LiveWeight $live): int
    {
        if (app()->isProduction()) {
            $this->error('Not in production: the real readings come from the gate agent.');

            return self::FAILURE;
        }
        $code = strtoupper((string) $this->option('entity'));
        [$entity, $scale, $sender] = $tenant->runAsSystem(function () use ($code): array {
            $entity = Entity::query()->where('code', $code)->first();
            $scale = $entity === null ? null : Scale::query()->where('entity_id', $entity->id)->where('status', 'active')->orderBy('code')->first();
            $sender = $entity === null ? null : LoginAccount::query()->with(['role', 'person', 'entity'])
                ->where('entity_id', $entity->id)->where('username', strtolower($code).'.demo.clerk')->first();

            return [$entity, $scale, $sender];
        });
        if ($entity === null || $scale === null || $sender === null) {
            $this->error("Run the demo seeders first (php artisan db:seed --class=DemoActivitySeeder): no mill {$code}, weighbridge or demo clerk.");

            return self::FAILURE;
        }
        app(AuthContextBinder::class)->bind($sender);

        $target = (int) $this->option('kg');
        $until = time() + (int) $this->option('seconds');
        $this->info("Sending readings for {$scale->code} until ".now('Asia/Karachi')->addSeconds($until - time())->format('H:i:s').' PKT (Ctrl+C to stop). Truck: '.number_format($target).' kg.');

        while (time() < $until) {
            foreach ($this->cycle($target, (int) $scale->resolution_kg ?: 10) as [$kg, $stable, $phase]) {
                if (time() >= $until) {
                    break;
                }
                $live->record(['scale_id' => $scale->id, 'kg' => $kg, 'stable' => $stable]);
                $this->line(sprintf('%s  %8s kg  %s', now('Asia/Karachi')->format('H:i:s'), number_format($kg), $phase));
                sleep(1);
            }
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: int, 1: bool, 2: string}> one truck: empty, driving on, standing (45 s), driving off */
    private function cycle(int $target, int $step): array
    {
        $readings = array_fill(0, 5, [0, true, 'empty']);
        foreach ([0.2, 0.45, 0.7, 0.9, 1.03, 0.98] as $share) {
            $readings[] = [(int) (round($target * $share / $step) * $step), false, 'moving'];
        }
        $readings = array_merge($readings, array_fill(0, 45, [$target, true, 'stable']));
        foreach ([0.6, 0.25] as $share) {
            $readings[] = [(int) (round($target * $share / $step) * $step), false, 'moving'];
        }

        return $readings;
    }
}
