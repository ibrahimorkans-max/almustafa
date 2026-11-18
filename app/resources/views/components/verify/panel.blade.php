@props([
    'steps' => [],          // list of labels
    'at' => 0,              // index of the current step (count = all done)
    'title' => '',
    'subtitle' => null,
    'state' => 'working',   // working | waiting | done | failed
    'leave' => false,       // done: the card leaves (tilt up and fade) as the dashboard opens
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
<div class="rmv" data-state="{{ $state }}" {{ $attributes }}
    @if ($state === 'done' && $leave) x-data x-init="setTimeout(() => $el.classList.add('rmv-exit'), 1700)" @endif>
    {{-- Read out on every change of state. --}}
    <span class="sr-only" aria-live="polite">{{ match ($state) {
        'done' => __('Verified.'), 'failed' => __('Not verified.'),
        'waiting' => __('Waiting for approval.'), default => __('Verifying…'),
    } }} {{ $title }}</span>
    <div class="rmv-top">
        <div class="rmv-shield" aria-hidden="true">
            @if ($state === 'done')
                <span class="rmv-flash"></span>
                <svg class="rmv-check rmv-icon-ok" viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="none" stroke="rgb(var(--rmv-ok))" stroke-width="3.5"/><path d="M15 27 l7 7 l15 -16" fill="none" stroke="rgb(var(--rmv-ok))" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            @elseif ($state === 'failed')
                <span class="rmv-bad-ring"></span>
                <svg class="rmv-icon-bad" viewBox="0 0 52 52"><circle cx="26" cy="26" r="24" fill="rgb(var(--rmv-bad))"/><path d="M18 18 L34 34 M34 18 L18 34" stroke="#0f1115" stroke-width="5" stroke-linecap="round"/></svg>
            @else
                <span class="rmv-radar"></span><span class="rmv-ring"></span><span class="rmv-ring"></span><span class="rmv-ring"></span>
                <svg viewBox="0 0 64 64"><path d="M32 6 L52 13 V30 C52 44 43 53 32 58 C21 53 12 44 12 30 V13 Z" fill="rgba(79,125,255,.18)" stroke="rgb(var(--rmv-accent))" stroke-width="2.5"/><rect x="24" y="29" width="16" height="12" rx="2.5" fill="rgb(var(--rmv-accent))"/><path d="M27 29 v-3.5 a5 5 0 0 1 10 0 V29" fill="none" stroke="rgb(var(--rmv-accent))" stroke-width="2.5"/></svg>
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
