<div wire:poll.10s>
    <datalist id="weigh-vehicles">
        @foreach ($waiting as $w)@if ($w['plate'] !== '')<option value="{{ $w['plate'] }}">{{ $w['label'] }}</option>@endif @endforeach
    </datalist>
    <div class="mb-1 text-xs font-medium text-gray-500">Waiting (token order) — tap to fill in{{ $waiting !== [] ? ' · '.count($waiting) : '' }}</div>
    @if ($waiting === [])
        <p class="text-xs text-gray-500">No vehicle is waiting for a weight.</p>
    @else
        <div class="flex flex-wrap gap-1">
            @foreach (array_slice($waiting, 0, \App\Livewire\WeighQueue::SHOWN) as $w)
                <button type="button" wire:key="wq-{{ $w['id'] }}" wire:click="$parent.pick('{{ $scaleId }}', '{{ $w['id'] }}')"
                    @class([
                        'rounded-full px-2.5 py-1 text-xs ring-1 hover:bg-gray-50 dark:hover:bg-white/5',
                        'ring-gray-300 dark:ring-white/20' => $w['kind'] !== null,
                        'text-gray-500 ring-gray-200 dark:ring-white/10' => $w['kind'] === null,
                    ])>{{ $w['label'] }}</button>
            @endforeach
        </div>
        @if (count($waiting) > \App\Livewire\WeighQueue::SHOWN)
            <p class="mt-1 text-xs text-gray-500">and {{ count($waiting) - \App\Livewire\WeighQueue::SHOWN }} more: type the vehicle number to find one.</p>
        @endif
    @endif
</div>
