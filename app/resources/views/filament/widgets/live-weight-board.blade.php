<x-filament-widgets::widget>
    {{-- Not refreshed by itself: only the clock's reading changes (every second, in the browser), so typing is never interrupted. --}}
    <div>
        @php
            $scales = $this->getScales();
            $waiting = $this->getWaiting();
            $input = 'block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5';
        @endphp
        @if ($scales === [])
            <x-filament::section><p class="text-sm text-gray-500">No weighbridge is set up yet (Gate &amp; weighbridge → Weighbridges, and Connect a weighbridge for the steps).</p></x-filament::section>
        @else
            <datalist id="weigh-items">
                @foreach ($this->getProductNames() as $name)<option value="{{ $name }}">@endforeach
            </datalist>
            <datalist id="weigh-parties">
                @foreach ($this->getPartyNames() as $name)<option value="{{ $name }}">@endforeach
            </datalist>
            <datalist id="weigh-vehicles">
                @foreach ($waiting as $w)@if ($w['plate'] !== '')<option value="{{ $w['plate'] }}">{{ $w['label'] }}</option>@endif @endforeach
            </datalist>

            @php
                $s = $this->currentScale();
                $sid = $s['scale_id'];
            @endphp
            <x-filament::section>
                @if (count($scales) > 1)
                    {{-- One clock: choose which weighbridge it shows. --}}
                    <div class="mb-3 flex flex-wrap justify-center gap-2" role="tablist" aria-label="Weighbridge">
                        @foreach ($scales as $other)
                            <button type="button" role="tab" wire:click="showScale('{{ $other['scale_id'] }}')" aria-selected="{{ $other['scale_id'] === $sid ? 'true' : 'false' }}"
                                @class([
                                    'rounded-lg px-3 py-1.5 text-sm ring-1',
                                    'bg-primary-600 text-white ring-primary-600' => $other['scale_id'] === $sid,
                                    'text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-white/20' => $other['scale_id'] !== $sid,
                                ])>{{ $other['code'] }} · {{ $other['name'] }}</button>
                        @endforeach
                    </div>
                @endif

                <div class="flex flex-col items-center gap-6 md:flex-row md:items-start">
                    {{-- The clock reads the weighbridge by itself in the browser (public/js/live-weight.js): no request
                         redraws this widget, so the page never reloads while a vehicle is being weighed. --}}
                    <div style="flex: 0 0 240px; width: 240px; max-width: 100%;" wire:ignore wire:key="dial-{{ $sid }}"
                        data-live-weight="{{ route('filament.admin.gate.live-weight', ['scale' => $sid]) }}" data-capacity="{{ $s['capacity'] }}" data-name="{{ $s['name'] }}">
                        @include('filament.widgets.partials.weight-dial', ['kg' => $s['kg'], 'capacity' => $s['capacity'], 'live' => $s['live'],
                            'stable' => $s['stable'], 'code' => $s['code'], 'name' => $s['name'], 'age' => $s['age_seconds']])
                    </div>
                    <div class="w-full min-w-0 flex-1">

                        @if ($this->canRecord() || $this->canType())
                            <div class="space-y-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-xs font-medium text-gray-500">Vehicle number</span>
                                        <input type="text" list="weigh-vehicles" wire:model.live.debounce.400ms="plate.{{ $sid }}" placeholder="MNA-21-1234"
                                            class="{{ $input }} font-mono uppercase" autocomplete="off">
                                    </label>
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-xs font-medium text-gray-500">Party name</span>
                                        <input type="text" list="weigh-parties" wire:model.live.debounce.500ms="party.{{ $sid }}" placeholder="Supplier or buyer"
                                            class="{{ $input }}" autocomplete="off">
                                    </label>
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-xs font-medium text-gray-500">Type</span>
                                        <select wire:model.live="jack.{{ $sid }}" class="{{ $input }}">
                                            <option value="">—</option>
                                            @foreach (\App\Models\Gate\GateEntry::JACK_TYPES as $jk => $jl)
                                                <option value="{{ $jk }}">{{ $jl }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-xs font-medium text-gray-500">Item / goods (what it carries)</span>
                                        <input type="text" list="weigh-items" wire:model.live.debounce.500ms="item.{{ $sid }}" placeholder="e.g. Paddy 1121, Rice, Husk 120 bags"
                                            class="{{ $input }}" autocomplete="off">
                                    </label>
                                </div>
                                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Inbound or outbound">
                                    @foreach (\App\Domain\Gate\QuickWeigh::KINDS as $k => $label)
                                        @php($on = ($this->kind[$sid] ?? 'paddy') === $k)
                                        <button type="button" wire:click="$set('kind.{{ $sid }}', '{{ $k }}')" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}"
                                            @class([
                                                'rounded-lg px-3 py-1.5 text-sm ring-1',
                                                'bg-primary-600 text-white ring-primary-600' => $on,
                                                'text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/5' => ! $on,
                                            ])>{{ $label }}</button>
                                    @endforeach
                                </div>

                                @php($st = $this->stepsFor($sid))
                                @if ($st !== null)
                                    @php($e = $st['entry'])
                                    <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                                        <div class="font-medium">{{ $e->token_no !== null || $e->token_serial !== null ? 'Token '.$e->tokenLabel().' · ' : '' }}{{ $e->jack_type !== null ? $e->jackLabel().' · ' : '' }}{{ $e->vehicleLabel() }} · {{ $e->partyLabel() ?? '—' }} · {{ $e->itemLabel() }}</div>
                                        <div class="mt-1 grid grid-cols-3 gap-2 text-center">
                                            <div><div class="text-xs text-gray-500">Loaded (gross)</div><div class="font-mono">{{ $st['gross']['kg'] !== null ? number_format((float) $st['gross']['kg'], 0) : '—' }}</div></div>
                                            <div><div class="text-xs text-gray-500">Empty (tare)</div><div class="font-mono">{{ $st['tare']['kg'] !== null ? number_format((float) $st['tare']['kg'], 0) : '—' }}</div></div>
                                            <div><div class="text-xs text-gray-500">Net</div><div class="font-mono font-semibold">{{ $st['net'] !== null ? number_format((float) $st['net'], 0) : '—' }}</div></div>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            {{ $st['next'] !== null ? 'Next: '.$this->nextLabel($e).'.' : $this->waitingReason($e) }}
                                        </div>
                                    </div>
                                    @if ($st['unload'])
                                        <label class="block text-sm">
                                            <span class="mb-1 block text-xs font-medium text-gray-500">Unloaded into (before the empty weight)</span>
                                            <select wire:model.live="godown.{{ $sid }}" class="{{ $input }}">
                                                <option value="">Choose the godown…</option>
                                                @foreach ($this->getGodowns() as $gid => $gname)
                                                    <option value="{{ $gid }}">{{ $gname }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                @endif

                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($this->canRecord())
                                        @php($next = $st['next'] ?? null)
                                        <x-filament::button size="lg" icon="heroicon-o-scale" wire:click="recordWeight('{{ $sid }}')" wire:loading.attr="disabled">
                                            {{ $next === 'tare' ? 'Record empty weight (tare)' : ($next === 'gross' ? 'Record loaded weight (gross)' : 'Record weight') }}
                                        </x-filament::button>
                                    @endif
                                    @if ($this->canType())
                                        {{ ($this->typeWeightAction)(['scale' => $sid]) }}
                                    @endif
                                </div>
                                @if ($this->canRecord())
                                    <p class="text-xs text-gray-500">Press when the clock shows STABLE.</p>
                                @endif

                                @if ($waiting !== [])
                                    <div>
                                        <div class="mb-1 text-xs font-medium text-gray-500">Waiting (token order) — tap to fill in</div>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach (array_slice($waiting, 0, 12) as $w)
                                                <button type="button" wire:click="pick('{{ $sid }}', '{{ $w['id'] }}')"
                                                    class="rounded-full px-2.5 py-1 text-xs ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-white/20 dark:hover:bg-white/5">{{ $w['label'] }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
    <x-filament-actions::modals />
</x-filament-widgets::widget>
