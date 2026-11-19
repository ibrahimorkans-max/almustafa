@props([
    'choices' => [],           // the four numbers (the e-mail's among them)
    'method' => 'pickNumber',  // the Livewire method given the number picked
])
{{--
    The second code of the e-mail (App\Domain\Auth\EmailCode): its 2-digit number, picked from four. Picking moves on
    by itself; a wrong pick cancels the e-mail's codes.
--}}
<div class="rmv-enter space-y-3" {{ $attributes }}>
    <p class="text-sm font-semibold">{{ __('✓ Code accepted. Now tap the 2-digit number shown in the same e-mail:') }}</p>
    <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem" role="group" aria-label="{{ __('Pick the number from the e-mail') }}">
        @foreach ($choices as $i => $n)
            <button type="button" wire:click="{{ $method }}({{ (int) $n }})" wire:loading.attr="disabled"
                class="rmv-pick rounded-2xl border-2 border-primary-600 bg-white font-bold text-primary-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-primary-50 focus:outline-none focus:ring-4 focus:ring-primary-500/40 dark:bg-gray-900 dark:text-primary-400"
                style="--i: {{ $i }}; padding: 1.1rem 0; font-size: 2.4rem; line-height: 1" dir="ltr">{{ $n }}</button>
        @endforeach
    </div>
    <p class="text-xs text-gray-500">{{ __('Only one chance: a wrong number cancels the e-mail and a new one must be sent. Never tell these codes to anybody.') }}</p>
</div>
