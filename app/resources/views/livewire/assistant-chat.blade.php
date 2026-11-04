<div x-data="{ open: $persist(false).as('mnx-assistant-open') }" x-on:keydown.escape.window="open = false" class="mnx-assistant">
    <style>
        .mnx-assistant-fab { position: fixed; right: 20px; bottom: 20px; z-index: 39; width: 56px; height: 56px; border-radius: 9999px; border: 0;
            display: flex; align-items: center; justify-content: center; cursor: pointer; color: #fff;
            background: rgb(var(--primary-600)); box-shadow: 0 8px 24px rgba(0,0,0,.22); transition: transform .15s; }
        .mnx-assistant-fab:hover { transform: scale(1.06); background: rgb(var(--primary-500)); }
        .mnx-assistant-fab svg { width: 28px; height: 28px; }
        .mnx-assistant-panel { position: fixed; right: 20px; bottom: 88px; z-index: 39; width: min(420px, calc(100vw - 32px)); height: min(620px, calc(100vh - 110px));
            display: flex; flex-direction: column; border-radius: 16px; overflow: hidden; background: rgb(var(--gray-50));
            box-shadow: 0 16px 48px rgba(0,0,0,.28); border: 1px solid rgba(0,0,0,.08); }
        .dark .mnx-assistant-panel { background: rgb(var(--gray-950)); border-color: rgba(255,255,255,.1); }
        .mnx-assistant-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .6rem .8rem; color: #fff; background: rgb(var(--primary-600)); }
        .mnx-assistant-head a, .mnx-assistant-head button { color: #fff; opacity: .9; background: none; border: 0; cursor: pointer; font-size: .75rem; }
        .mnx-assistant-head a:hover, .mnx-assistant-head button:hover { opacity: 1; text-decoration: underline; }
        .mnx-assistant-body { flex: 1; overflow-y: auto; padding: .75rem; display: flex; flex-direction: column; gap: .6rem; }
        .mnx-assistant-foot { padding: .6rem .75rem .75rem; border-top: 1px solid rgba(0,0,0,.08); background: inherit; }
        .dark .mnx-assistant-foot { border-color: rgba(255,255,255,.1); }
        @media (max-width: 640px) {
            .mnx-assistant-panel { right: 8px; left: 8px; bottom: 80px; width: auto; height: calc(100vh - 96px); }
            .mnx-assistant-fab { right: 14px; bottom: 14px; }
        }
        @media print { .mnx-assistant { display: none !important; } }
    </style>

    <div class="mnx-assistant-panel" x-show="open" x-cloak x-transition.opacity.duration.150ms role="dialog" aria-label="Assistant">
        <div class="mnx-assistant-head">
            <strong style="font-size:.9rem;">Assistant</strong>
            <span style="display:flex; gap:.75rem; align-items:center;">
                <button type="button" wire:click="clearChat">New chat</button>
                <a href="{{ $fullUrl }}">Full screen</a>
                <button type="button" x-on:click="open = false" aria-label="Close" style="font-size:1.1rem; line-height:1;">✕</button>
            </span>
        </div>
        <div style="padding: .5rem .75rem 0;">@include('filament.partials.assistant-tabs')</div>
        <div class="mnx-assistant-body" x-ref="body"
            x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight);
                $watch('open', v => v && $nextTick(() => $el.scrollTop = $el.scrollHeight));
                Livewire.hook('commit', ({ component, succeed }) => { if (component.id === $wire.$id) { succeed(() => setTimeout(() => { $el.scrollTop = $wire.tab === 'chat' ? $el.scrollHeight : 0; }, 30)); } });">
            @if ($tab === 'features')
                @include('filament.partials.assistant-features')
            @elseif ($tab === 'history')
                @include('filament.partials.assistant-history')
            @else
                @include('filament.partials.assistant-thread')
            @endif
        </div>
        <div class="mnx-assistant-foot">
            @include('filament.partials.assistant-composer')
        </div>
    </div>

    <button type="button" class="mnx-assistant-fab" x-on:click="open = ! open; open && $nextTick(() => $root.querySelector('textarea')?.focus())"
        x-bind:aria-expanded="open" title="Assistant">
        <svg x-show="! open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
        <svg x-show="open" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
    </button>
</div>
