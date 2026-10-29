@php($results = $this->getResults())
<x-filament-panels::page>
    <x-filament::section>
        <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
            <x-filament::input type="search" wire:model.live.debounce.400ms="q" autofocus
                placeholder="Token, vehicle, party, sauda, bill, invoice, voucher, employee, code…" />
        </x-filament::input.wrapper>
        <p class="mt-2 text-xs text-gray-500">At least 2 letters. Part of a name or number is enough. Ctrl + K searches from any screen.</p>
    </x-filament::section>

    @if ($results !== null)
        @php($categories = $results->getCategories())
        @if ($categories->isEmpty())
            <x-filament::section><p class="text-sm text-gray-500">Nothing found for “{{ $q }}”.</p></x-filament::section>
        @endif
        @foreach ($categories as $group => $items)
            <x-filament::section :heading="$group.' ('.count($items).')'" collapsible>
                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($items as $item)
                        <li class="py-2">
                            <a href="{{ $item->url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $item->title }}</a>
                            @if ($item->details !== [])
                                <div class="text-xs text-gray-500">
                                    @foreach ($item->details as $label => $value)<span class="me-3">{{ $label }}: {{ $value }}</span>@endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
