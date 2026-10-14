<x-filament-widgets::widget>
    <div wire:poll.1s>
        @php($scales = $this->getScales())
        @if ($scales === [])
            <x-filament::section><p class="text-sm text-gray-500">No weighbridge is set up yet (Gate &amp; weighbridge → Weighbridges).</p></x-filament::section>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($scales as $s)
                    <x-filament::section>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $s['code'] }} · {{ $s['name'] }}</div>
                                <div class="mt-1 font-mono text-5xl font-bold tabular-nums {{ $s['live'] ? 'text-gray-950 dark:text-white' : 'text-gray-400' }}" aria-live="polite">
                                    {{ $s['kg'] === null ? '— —' : number_format($s['kg'], 0) }}<span class="ml-2 text-xl font-medium text-gray-500">kg</span>
                                </div>
                                @if ($s['kg'] !== null)
                                    <div class="mt-1 text-sm text-gray-500">{{ number_format($s['kg'] / 40, 2) }} maund</div>
                                @endif
                            </div>
                            @if (! $s['live'])
                                <x-filament::badge color="gray" icon="heroicon-o-signal-slash">No signal{{ $s['age_seconds'] !== null ? ' · '.$s['age_seconds'].' s' : '' }}</x-filament::badge>
                            @elseif ($s['stable'])
                                <x-filament::badge color="success" icon="heroicon-o-check-circle">Stable</x-filament::badge>
                            @else
                                <x-filament::badge color="warning" icon="heroicon-o-arrows-up-down">Moving</x-filament::badge>
                            @endif
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
