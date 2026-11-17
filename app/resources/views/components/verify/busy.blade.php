@props(['target' => null, 'text' => null])
{{-- Full-screen "Verifying…" while a check runs (shown by Livewire only during that request). --}}
<x-verify.styles />
<div wire:loading.flex @if ($target) wire:target="{{ $target }}" @endif class="rmv-busy" style="display: none">
    <div class="rmv-busy-card">
        <div class="rmv-spin"></div>
        <div class="text-sm font-semibold">{{ $text ?? __('Verifying') }}<span class="rmv-dots"><span>.</span><span>.</span><span>.</span></span></div>
    </div>
</div>
