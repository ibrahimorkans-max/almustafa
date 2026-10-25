<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Gate\LiveWeight;
use App\Filament\Widgets\LiveWeightBoard;
use App\Models\Gate\Scale;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The weighbridge clock on its own: it refreshes itself every second, alone, so the weighing form next to
 * it is never redrawn while someone types (App\Filament\Widgets\LiveWeightBoard).
 */
final class LiveWeightDial extends Component
{
    public string $scaleId;

    public function render(): View
    {
        abort_unless(LiveWeightBoard::canView(), 403);
        $reading = collect(app(LiveWeight::class)->all())->firstWhere('scale_id', $this->scaleId);
        $scale = Scale::query()->find($this->scaleId);

        return view('livewire.live-weight-dial', [
            'kg' => $reading['kg'] ?? null,
            'live' => (bool) ($reading['live'] ?? false),
            'stable' => (bool) ($reading['stable'] ?? false),
            'age' => $reading['age_seconds'] ?? null,
            'capacity' => (float) ($scale->capacity_kg ?? 60000),
            'code' => (string) ($scale->code ?? ''),
            'name' => (string) ($scale->name ?? ''),
        ]);
    }
}
