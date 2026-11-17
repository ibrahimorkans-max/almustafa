@props([
    'steps' => [],          // list of labels
    'at' => 0,              // index of the current step (count = all done)
    'title' => '',
    'subtitle' => null,
    'state' => 'working',   // working | waiting | done | failed
])
@php
    $total = max(1, count($steps));
    $percent = (int) round(min($at, $total) / $total * 100);
    if ($state === 'working' || $state === 'waiting') {
        $percent = max(8, min(95, $percent + (int) round(50 / $total)));   // the current step is under way
    }
    if ($state === 'done') {
        $percent = 100;
    }
@endphp

{{-- Animated verification panel: shield, progress bar and steps (shared by the sign-in checks). --}}
<x-verify.styles />

<div class="rmv" data-state="{{ $state }}" {{ $attributes }}>
    <div class="rmv-top">
        <div class="rmv-shield" aria-hidden="true">
            @if ($state === 'done')
                <svg class="rmv-check" viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="none" stroke="rgb(var(--success-600))" stroke-width="4"/><path d="M15 27 l7 7 l15 -16" fill="none" stroke="rgb(var(--success-600))" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            @elseif ($state === 'failed')
                <svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="rgb(var(--danger-600))"/><path d="M18 18 L34 34 M34 18 L18 34" stroke="#fff" stroke-width="5" stroke-linecap="round"/></svg>
            @else
                <svg viewBox="0 0 64 64"><path d="M32 4 L54 12 V30 C54 45 44 55 32 60 C20 55 10 45 10 30 V12 Z" fill="rgb(var(--primary-600))"/><rect x="23" y="29" width="18" height="14" rx="3" fill="#fff"/><path d="M26 29 v-4 a6 6 0 0 1 12 0 v4" fill="none" stroke="#fff" stroke-width="3"/></svg>
                <span class="rmv-scan"></span>
            @endif
        </div>
        <div class="min-w-0">
            <div class="rmv-title">{{ $title }}</div>
            @if ($subtitle)<div class="rmv-sub">{{ $subtitle }}</div>@endif
        </div>
    </div>

    <div class="rmv-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"><div class="rmv-fill" style="width: {{ $percent }}%"></div></div>
    <div class="rmv-pct"><span>{{ __('Security check') }}</span><span dir="ltr">{{ $percent }}%</span></div>

    <div class="rmv-steps">
        @foreach ($steps as $i => $label)
            @php $s = $i < $at || $state === 'done' ? 'done' : ($i === $at ? 'now' : 'next'); @endphp
            <div class="rmv-step" data-s="{{ $s }}">
                <span class="rmv-dot">{{ $s === 'done' ? '✓' : $i + 1 }}</span>
                <span>{{ $label }}</span>
            </div>
        @endforeach
    </div>
</div>
