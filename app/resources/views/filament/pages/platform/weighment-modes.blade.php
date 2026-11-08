<x-filament-panels::page>
    @php($short = \App\Domain\Gate\WeighModes::SHORT)
    @php($names = $this->getNames())
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Only you (the SaaS owner) switch these on or off. A mill's Owner and staff see the modes switched on for their mill and
            pick among them; they cannot change them. A change counts for vehicles registered after it: vehicles already in a mill keep their mode.
        </p>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @foreach (\App\Domain\Gate\WeighModes::LABELS as $mode => $label)
                <div class="rounded-lg border border-gray-200 p-2 text-xs dark:border-white/10">
                    <div class="font-semibold">{{ $label }}</div>
                    <div class="text-gray-500">{{ \App\Domain\Gate\WeighModes::HELP[$mode] }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::input.wrapper class="max-w-md">
        <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Search a company or mill" />
    </x-filament::input.wrapper>

    @forelse ($this->getCompanies() as $c)
        <x-filament::section wire:key="company-{{ $c['id'] }}">
            <x-slot name="heading">{{ $c['name'] }} @if ($c['alias']) <span class="text-sm font-normal text-gray-500">({{ $c['alias'] }})</span> @endif</x-slot>
            <x-slot name="headerEnd">{{ ($this->companyAction)(['customer' => $c['id']]) }} {{ ($this->labAction)(['customer' => $c['id']]) }}</x-slot>
            <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-gray-500">Company defaults:</span>
                @if (! $c['set'])
                    <x-filament::badge color="gray">Not set — each mill keeps its earlier weighing policy</x-filament::badge>
                @elseif ($c['modes'] === [])
                    <x-filament::badge color="danger">All modes off</x-filament::badge>
                @else
                    @foreach ($c['modes'] as $m)<x-filament::badge color="success">{{ $short[$m] }}</x-filament::badge>@endforeach
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1 pr-3">Mill</th><th class="pr-3">Setting</th><th class="pr-3">Modes on now</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($c['mills'] as $m)
                            <tr class="border-t border-gray-100 dark:border-white/5" wire:key="mill-{{ $m['id'] }}">
                                <td class="py-1.5 pr-3 font-medium">{{ $m['name'] }}</td>
                                <td class="pr-3">{{ $m['own'] ? 'Its own' : 'Company' }} <span class="text-xs text-gray-400">{{ $m['version'] }}</span></td>
                                <td class="pr-3">
                                    @forelse ($m['modes'] as $mode)<x-filament::badge color="{{ $m['own'] ? 'warning' : 'success' }}" class="mr-1 inline-flex">{{ $short[$mode] }}</x-filament::badge>@empty<x-filament::badge color="danger">None</x-filament::badge>@endforelse
                                </td>
                                <td class="whitespace-nowrap text-right">{{ ($this->millAction)(['mill' => $m['id']]) }} {{ ($this->labAction)(['mill' => $m['id']]) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-gray-500">No mills yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <p class="text-sm text-gray-500">No company matches.</p>
    @endforelse

    <x-filament::section heading="Change history" collapsible>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1 pr-3">When</th><th class="pr-3">Company / mill</th><th class="pr-3">Before</th><th class="pr-3">After</th><th class="pr-3">By</th><th>Why</th></tr></thead>
            <tbody>
                @forelse ($this->getHistory() as $h)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1.5 pr-3 whitespace-nowrap">{{ $h->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }}</td>
                        <td class="pr-3">{{ $names[$h->entity_id ?? $h->customer_id] ?? '—' }} <span class="text-xs text-gray-400">{{ $h->level }} v{{ $h->version }}</span></td>
                        <td class="pr-3 text-xs">{{ $h->before === null ? '(company defaults)' : (implode(', ', array_map(fn ($m) => $short[$m] ?? $m, $h->before)) ?: 'none') }}</td>
                        <td class="pr-3 text-xs">{{ $h->after === null ? 'Follows its company' : (implode(', ', array_map(fn ($m) => $short[$m] ?? $m, $h->after)) ?: 'none') }}</td>
                        <td class="pr-3">{{ $h->changer?->username ?? '—' }}</td>
                        <td class="text-xs">{{ $h->reason ?? '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">No changes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
