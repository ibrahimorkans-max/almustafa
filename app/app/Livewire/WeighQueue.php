<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Gate\LiveCapture;
use App\Filament\Widgets\LiveWeightBoard;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The vehicles waiting for a weight, in token order, beside the weighing form (App\Filament\Widgets\LiveWeightBoard).
 * It refreshes itself every few seconds and after each weight, alone, so a vehicle that arrives at the gate shows up
 * without reloading the page and without redrawing the form someone is typing in. Tapping one fills in the form.
 */
final class WeighQueue extends Component
{
    /** Chips shown; the rest are counted. */
    public const SHOWN = 40;

    public string $scaleId;

    #[On('weigh-queue-refresh')]
    public function refreshQueue(): void
    {
        // Rendering again is the refresh.
    }

    public function render(): View
    {
        abort_unless(LiveWeightBoard::canView(), 403);
        $user = Filament::auth()->user();
        $may = $user instanceof LoginAccount && ($user->hasPermission('weighment.capture') || $user->hasPermission('weighment.manual'));

        return view('livewire.weigh-queue', ['waiting' => $may ? app(LiveCapture::class)->waiting()->all() : []]);
    }
}
