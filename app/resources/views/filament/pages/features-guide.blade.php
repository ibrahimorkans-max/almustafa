<x-filament-panels::page>
    @foreach ($this->groups() as $group => $items)
        <x-filament::section :heading="$group !== '' ? $group : __('General')" collapsible>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($items as $item)
                    <a href="{{ $item['url'] }}" class="flex gap-3 rounded-lg border border-gray-200 p-3 transition hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                        @if ($item['icon'])
                            <x-filament::icon :icon="$item['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-primary-600" />
                        @endif
                        <span class="min-w-0">
                            <span class="block font-medium text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                            @if ($item['description'])
                                <span class="mt-0.5 block text-sm text-gray-600 dark:text-gray-400">{{ $item['description'] }}</span>
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
