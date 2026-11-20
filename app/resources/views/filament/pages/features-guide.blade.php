<x-filament-panels::page>
    <div x-data="{ q: '' }" class="space-y-6">
        <div>
            <label class="sr-only" for="fg-search">{{ __('Search the guide') }}</label>
            <input id="fg-search" type="search" x-model.debounce.200ms="q" placeholder="{{ __('Search: bill, salary, stock, password …') }}"
                class="w-full max-w-md rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>

        @foreach ($this->groups() as $group => $items)
            <x-filament::section :heading="$group !== '' ? $group : __('General')" collapsible>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($items as $item)
                        @php
                            $haystack = mb_strtolower($item['label'].' '.$item['description'].' '.($item['how']['how'] ?? '').' '.implode(' ', $item['how']['steps'] ?? []));
                        @endphp
                        <div class="rounded-lg border border-gray-200 p-3 transition hover:border-primary-500 dark:border-white/10"
                            x-show="q === '' || @js($haystack).includes(q.toLowerCase())">
                            <a href="{{ $item['url'] }}" class="flex gap-3 hover:opacity-80">
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
                            @if ($item['how'])
                                <details class="mt-2 text-sm">
                                    <summary class="cursor-pointer font-medium text-primary-600">{{ __('How to use') }}</summary>
                                    <p class="mt-2 text-gray-700 dark:text-gray-300"><span class="font-semibold">{{ __('How it works') }}:</span> {{ $item['how']['how'] }}</p>
                                    <ol class="mt-2 list-decimal space-y-1 ps-5 text-gray-700 dark:text-gray-300">
                                        @foreach ($item['how']['steps'] as $step)
                                            <li>{{ $step }}</li>
                                        @endforeach
                                    </ol>
                                    <a href="{{ $item['url'] }}" class="mt-2 inline-block text-primary-600 underline">{{ __('Open :screen', ['screen' => $item['label']]) }}</a>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
