<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Gate\LiveWeight;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Each weighbridge's reading as it changes (refreshed every second): the weight, "stable" when the
 * indicator has settled, "moving" while a truck rolls on, "no signal" when the gate agent stopped
 * sending. Display only: weights are recorded by the agent's stable capture.
 */
class LiveWeightBoard extends Widget
{
    protected static string $view = 'filament.widgets.live-weight-board';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('gate.entry') || $user->hasPermission('weighment.capture') || $user->hasPermission('dashboard.view'));
    }

    /** @return list<array{scale_id: string, code: string, name: string, kg: float|null, stable: bool, live: bool, age_seconds: int|null}> */
    public function getScales(): array
    {
        return app(LiveWeight::class)->all();
    }
}
