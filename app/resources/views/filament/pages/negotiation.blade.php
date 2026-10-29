<x-filament-panels::page>
    @php
        $vehicles = $this->vehicles();
        $entry = $this->entry();
        $tones = [
            'success' => 'bg-success-500', 'danger' => 'bg-danger-500', 'warning' => 'bg-warning-500', 'info' => 'bg-info-500', 'gray' => 'bg-gray-400',
        ];
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section class="lg:col-span-1">
            <x-slot name="heading">Vehicles in negotiation</x-slot>
            <x-slot name="description">In token order. Choose one to see its timeline.</x-slot>
            @forelse ($vehicles as $v)
                <button type="button" wire:click="choose('{{ $v['id'] }}')"
                    @class([
                        'mb-1 block w-full rounded-lg px-3 py-2 text-left text-sm',
                        'bg-primary-50 ring-1 ring-primary-500 dark:bg-primary-500/10' => $entry?->id === $v['id'],
                        'hover:bg-gray-50 dark:hover:bg-white/5' => $entry?->id !== $v['id'],
                    ])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold">{{ $v['token'] !== null ? 'Token '.$v['token'].' · ' : '' }}{{ $v['vehicle'] }}</span>
                        <span class="text-xs text-gray-500">{{ $v['price'] }}</span>
                    </div>
                    <div class="text-xs text-gray-500">{{ $v['party'] }} — {{ $v['stage'] }}</div>
                </button>
            @empty
                <p class="text-sm text-gray-500">No vehicle is being negotiated now.</p>
            @endforelse
        </x-filament::section>

        <div class="space-y-6 lg:col-span-2">
            @if ($entry === null)
                <x-filament::section><p class="text-sm text-gray-500">Choose a vehicle on the left.</p></x-filament::section>
            @else
                @php($lab = $this->labReport())
                <x-filament::section>
                    <x-slot name="heading">{{ $entry->token_no !== null || $entry->token_serial !== null ? 'Token '.$entry->tokenLabel().' · ' : '' }}{{ $entry->vehicleLabel() }}</x-slot>
                    <x-slot name="description">{{ $entry->number }} · {{ $entry->party->name ?? '—' }} · {{ $entry->product->name ?? '—' }} · sauda {{ $entry->sauda->number ?? '—' }}</x-slot>
                    <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div><dt class="text-xs text-gray-500">Loaded (gross)</dt><dd class="font-mono">{{ $entry->gross_kg !== null ? number_format((float) $entry->gross_kg, 0).' kg' : '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Empty (tare)</dt><dd class="font-mono">{{ $entry->tare_kg !== null ? number_format((float) $entry->tare_kg, 0).' kg' : '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Net</dt><dd class="font-mono font-semibold">{{ $entry->net_kg !== null ? number_format((float) $entry->net_kg, 0).' kg' : '—' }}</dd></div>
                        <div><dt class="text-xs text-gray-500">Payable</dt><dd class="font-mono font-semibold">{{ $entry->payable_kg !== null ? number_format((float) $entry->payable_kg, 0).' kg' : '—' }}</dd></div>
                    </dl>
                    @if ($lab['test'] !== null)
                        <div class="mt-4 border-t border-gray-200 pt-3 text-sm dark:border-white/10">
                            <div class="mb-1 font-medium">Lab: {{ \App\Domain\Gate\LabReport::decisionLabel($lab) }}</div>
                            <ul class="space-y-0.5 text-xs">
                                @foreach ($lab['rows'] as $r)
                                    <li @class(['font-semibold text-danger-600' => $r['verdict'] === 'over', 'text-warning-600' => $r['verdict'] === 'grace'])>{{ \App\Domain\Gate\LabStandards::describe($r) }}</li>
                                @endforeach
                            </ul>
                            @if ($lab['rejected_reason'])
                                <div class="mt-2 rounded-lg bg-danger-50 p-2 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400"><strong>Rejected because:</strong> {{ $lab['rejected_reason'] }}</div>
                            @endif
                        </div>
                    @endif
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">Timeline</x-slot>
                    <x-slot name="description">Every price and Caat proposal and decision, with when, who and why. Nothing is changed afterwards; each step is its own record.</x-slot>
                    @php($timeline = $this->timeline())
                    @if ($timeline === [])
                        <p class="text-sm text-gray-500">Nothing negotiated yet. Use Propose price or Caat &amp; price settlement above.</p>
                    @else
                        <ol class="relative ms-2 border-s border-gray-200 dark:border-white/10">
                            @foreach ($timeline as $row)
                                <li class="mb-4 ms-4">
                                    <span class="absolute -start-1.5 mt-1.5 h-3 w-3 rounded-full {{ $tones[$row['tone']] ?? 'bg-gray-400' }}"></span>
                                    <div class="text-xs text-gray-500">{{ $row['at']->timezone('Asia/Karachi')->format('d M Y H:i') }} · {{ $row['who'] }}</div>
                                    <div class="text-sm font-semibold">{{ $row['what'] }}</div>
                                    @if ($row['detail'] !== '')
                                        <div class="text-sm text-gray-700 dark:text-gray-300">{{ $row['detail'] }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
