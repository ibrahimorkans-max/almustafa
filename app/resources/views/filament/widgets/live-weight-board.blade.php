<x-filament-widgets::widget>
    <div wire:poll.1s>
        @php($scales = $this->getScales())
        @php($waiting = $this->getWaiting())
        @if ($scales === [])
            <x-filament::section><p class="text-sm text-gray-500">No weighbridge is set up yet (Gate &amp; weighbridge → Weighbridges, and Connect a weighbridge for the steps).</p></x-filament::section>
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

                        @if ($this->canRecord())
                            <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-200 pt-3 dark:border-white/10">
                                <label class="min-w-0 flex-1 text-sm">
                                    <span class="mb-1 block text-xs font-medium text-gray-500">Vehicle on the platform</span>
                                    <select wire:model.live="selected.{{ $s['scale_id'] }}" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                        <option value="">{{ $waiting === [] ? 'No vehicle waits for a weight' : 'Choose…' }}</option>
                                        @foreach ($waiting as $w)
                                            <option value="{{ $w['id'] }}">{{ $w['label'] }} — needs {{ $w['kind'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                @php($chosen = collect($waiting)->firstWhere('id', $this->selected[$s['scale_id']] ?? null))
                                <x-filament::button wire:click="recordWeight('{{ $s['scale_id'] }}')" wire:loading.attr="disabled"
                                    :disabled="! ($s['live'] && $s['stable'] && $chosen !== null)" icon="heroicon-o-scale">
                                    {{ $chosen !== null ? 'Record '.$chosen['kind'] : 'Record weight' }}
                                </x-filament::button>
                            </div>
                        @endif
                    </x-filament::section>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
