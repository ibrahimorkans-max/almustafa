<x-filament-panels::page>
    @php($h = $this->health())
    @php($channels = \App\Domain\Messaging\SenderIdentities::CHANNELS)
    <x-filament::section heading="Integration health">
        <div class="flex flex-wrap gap-2 text-sm">
            <x-filament::badge :color="$h['whatsapp'] ? 'success' : 'danger'">WhatsApp connection: {{ $h['whatsapp'] ? 'connected' : 'not connected' }}</x-filament::badge>
            <x-filament::badge :color="$h['ai'] ? 'success' : 'danger'">AI provider: {{ $h['ai'] ? 'connected' : 'not connected' }}</x-filament::badge>
            <x-filament::badge color="info">One WhatsApp number for every mill; each message carries the mill's name</x-filament::badge>
            @if ($h['unassigned'] > 0)<x-filament::badge color="warning">Mills with WhatsApp switched off: {{ $h['unassigned'] }}</x-filament::badge>@endif
        </div>
        @if ($h['failed'] !== [])
            <p class="mt-3 text-sm font-medium text-danger-600">Failed calls in the last 24 hours</p>
            <ul class="mt-1 list-disc pl-5 text-xs">
                @foreach ($h['failed'] as $f)<li>{{ $f->provider }}: {{ $f->n }} — {{ \Illuminate\Support\Str::limit((string) $f->error, 140) }}</li>@endforeach
            </ul>
        @endif
        <p class="mt-3 text-xs text-gray-500">The connections themselves (WhatsApp, AI, push, webhook) are under Platform → Integrations, AI assistant and WhatsApp webhook. Mills never connect their own.</p>
    </x-filament::section>

    <x-filament::input.wrapper class="max-w-md">
        <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Search a company or mill" />
    </x-filament::input.wrapper>

    @foreach ($this->companies() as $c)
        <x-filament::section wire:key="sc-{{ $c['id'] }}" :heading="$c['name']" collapsible>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="py-1 pr-3"></th>@foreach ($channels as $l)<th class="pr-3">{{ $l }}</th>@endforeach</tr></thead>
                    <tbody>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <td class="py-1.5 pr-3 font-medium">Company default</td>
                            @foreach ($channels as $ch => $l)
                                @php($row = $c['own'][$ch] ?? null)
                                <td class="pr-3 align-top">
                                    @if ($row)<div>{{ $row->display_name }}</div><div class="text-xs text-gray-500">{{ $row->sender }} · {{ $row->status }}</div>@else<span class="text-xs text-gray-400">none</span>@endif
                                    {{ ($this->senderAction)(['channel' => $ch, 'customer' => $c['id']]) }}
                                </td>
                            @endforeach
                        </tr>
                        @foreach ($c['mills'] as $m)
                            <tr class="border-t border-gray-100 dark:border-white/5" wire:key="sm-{{ $m['id'] }}">
                                <td class="py-1.5 pr-3">{{ $m['name'] }}</td>
                                @foreach ($channels as $ch => $l)
                                    @php($own = $m['own'][$ch] ?? null)
                                    @php($eff = $m['effective'][$ch] ?? null)
                                    <td class="pr-3 align-top">
                                        @if ($eff)
                                            <div @class(['text-warning-600' => $eff->platform_sender])>{{ $eff->display_name }}</div>
                                            <div class="text-xs text-gray-500">{{ $own ? 'its own' : 'company' }} · {{ $eff->status }}{{ $eff->platform_sender ? ' · platform name' : '' }}</div>
                                        @else
                                            @if ($ch === 'whatsapp')
                                                <div>{{ \App\Domain\Messaging\SenderIdentities::nameFor($m['id']) }}</div><div class="text-xs text-gray-500">platform number · name from Branding</div>
                                            @else
                                                <x-filament::badge color="gray" size="sm">{{ $ch === 'email' ? 'mill name, platform address' : 'not assigned' }}</x-filament::badge>
                                            @endif
                                        @endif
                                        {{ ($this->senderAction)(['channel' => $ch, 'mill' => $m['id']]) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach
    <x-filament-actions::modals />
</x-filament-panels::page>
