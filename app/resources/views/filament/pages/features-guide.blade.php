<x-filament-panels::page>
    {{-- Filament's ready-made CSS lacks the responsive grid classes, so this page brings its own. --}}
    <style>
        .mnx-fg { display: flex; flex-direction: column; gap: 1.5rem; }
        .mnx-fg-search { width: 100%; max-width: 32rem; border-radius: .5rem; font-size: .875rem; }
        .mnx-fg-grid { display: grid; gap: .75rem; align-items: start;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 20rem), 1fr)); }
        @media (min-width: 1600px) { .mnx-fg-grid { gap: 1rem; } }
        .mnx-fg-card { border: 1px solid rgb(229 231 235); border-radius: .5rem; padding: .75rem;
            transition: border-color .15s; min-width: 0; }
        .mnx-fg-card:hover { border-color: rgb(var(--primary-500)); }
        .dark .mnx-fg-card { border-color: rgba(255,255,255,.1); }
        .dark .mnx-fg-card:hover { border-color: rgb(var(--primary-500)); }
        .mnx-fg-card details[open] { margin-top: .5rem; }
        .mnx-fg-steps { list-style: decimal; padding-inline-start: 1.25rem; margin-top: .5rem; }
        .mnx-fg-steps li + li { margin-top: .25rem; }
    </style>

    <div x-data="{ q: '' }" class="mnx-fg">
        <div>
            <label class="sr-only" for="fg-search">{{ __('Search the guide') }}</label>
            <input id="fg-search" type="search" x-model.debounce.200ms="q" placeholder="{{ __('Search: bill, salary, stock, password …') }}"
                class="mnx-fg-search border-gray-300 dark:border-white/10 dark:bg-white/5">
        </div>

        @foreach ($this->groups() as $group => $items)
            <x-filament::section :heading="$group !== '' ? $group : __('General')" collapsible>
                <div class="mnx-fg-grid">
                    @foreach ($items as $item)
                        @php
                            $haystack = mb_strtolower($item['label'].' '.$item['description'].' '.($item['how']['how'] ?? '').' '.implode(' ', $item['how']['steps'] ?? []));
                        @endphp
                        <div class="mnx-fg-card"
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
                                    <ol class="mnx-fg-steps text-gray-700 dark:text-gray-300">
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
