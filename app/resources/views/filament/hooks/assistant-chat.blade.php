{{-- The floating chat button on every screen (App\Livewire\AssistantChat); not on the Assistant page itself. --}}
@if (\App\Filament\Pages\AssistantPage::canAccess() && ! request()->routeIs(\App\Filament\Pages\AssistantPage::getRouteName()))
    @livewire(\App\Livewire\AssistantChat::class)
@endif
