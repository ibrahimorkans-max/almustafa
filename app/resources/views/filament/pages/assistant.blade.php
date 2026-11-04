<x-filament-panels::page>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
            <span>
                {{ $this->aiEnabled() ? 'AI mode: normal sentences in English or Roman Urdu.' : 'Command mode (offline). Add an AI API key to ask in normal sentences.' }}
                Anything that changes data is shown first and runs only when you press Confirm.
            </span>
            <x-filament::link tag="button" size="sm" color="gray" wire:click="clearChat" icon="heroicon-o-plus">New chat</x-filament::link>
        </div>

        <div style="max-width: 32rem;">@include('filament.partials.assistant-tabs')</div>

        @if ($tab === 'features')
            @include('filament.partials.assistant-features')
        @elseif ($tab === 'history')
            @include('filament.partials.assistant-history')
        @else
            <div class="flex flex-col gap-3" id="assistant-messages">
                @include('filament.partials.assistant-thread')
            </div>
        @endif

        <div class="sticky bottom-0 bg-gray-50 py-2 dark:bg-gray-950">
            @include('filament.partials.assistant-composer')
        </div>
    </div>
</x-filament-panels::page>
