{{--
    The signed-in person's role in the top bar, with its animated icon (App\Filament\Support\RoleLook), and a little
    life in the menu: its icons lift on hover and the open page's icon glows. No motion for people who asked their
    computer for less of it.
--}}
@php
    $mnxUser = filament()->auth()->user();
    $look = \App\Filament\Support\RoleLook::of($mnxUser instanceof \App\Models\LoginAccount ? $mnxUser : null);
@endphp
<style>
    .mnx-role { display: inline-flex; align-items: center; gap: .45rem; padding: .25rem .7rem .25rem .3rem; margin-right: .4rem; border-radius: 9999px;
        background: linear-gradient(135deg, {{ $look['from'] }}22, {{ $look['to'] }}22); border: 1px solid {{ $look['from'] }}55; white-space: nowrap; }
    .mnx-role-icon { position: relative; width: 1.75rem; height: 1.75rem; border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; color: #fff;
        background: linear-gradient(135deg, {{ $look['from'] }}, {{ $look['to'] }}); box-shadow: 0 2px 8px {{ $look['from'] }}66; overflow: hidden; }
    .mnx-role-icon svg { width: 1rem; height: 1rem; }
    .mnx-role-label { font-size: .75rem; font-weight: 700; color: {{ $look['to'] }}; }
    .dark .mnx-role-label { color: {{ $look['from'] }}; }
    @media (max-width: 768px) { .mnx-role-label { display: none; } .mnx-role { padding-right: .3rem; } }

    /* The motions, one per kind of role. */
    .mnx-m-drive svg { animation: mnx-drive 2.4s ease-in-out infinite; }
    .mnx-m-bubble svg { animation: mnx-bubble 1.8s ease-in-out infinite; transform-origin: 50% 90%; }
    .mnx-m-shine::after { content: ''; position: absolute; inset: 0; background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,.75) 50%, transparent 70%); animation: mnx-shine 2.8s ease-in-out infinite; }
    .mnx-m-shine svg { animation: mnx-float 2.8s ease-in-out infinite; }
    .mnx-m-pulse { animation: mnx-pulse 2.2s ease-in-out infinite; }
    .mnx-m-float svg { animation: mnx-float 2.6s ease-in-out infinite; }
    .mnx-m-tap svg { animation: mnx-tap 1.6s ease-in-out infinite; }
    .mnx-m-wobble svg { animation: mnx-wobble 2.4s ease-in-out infinite; }
    .mnx-m-spin svg { animation: mnx-spin 6s linear infinite; }
    .mnx-m-sway svg { animation: mnx-sway 2s ease-in-out infinite; }
    @keyframes mnx-drive { 0%, 100% { transform: translateX(-2px); } 50% { transform: translateX(2px) translateY(-.5px); } }
    @keyframes mnx-bubble { 0%, 100% { transform: rotate(0) scale(1); } 25% { transform: rotate(-8deg) scale(1.05); } 75% { transform: rotate(8deg) scale(1.05); } }
    @keyframes mnx-shine { 0% { transform: translateX(-120%); } 60%, 100% { transform: translateX(120%); } }
    @keyframes mnx-pulse { 0%, 100% { box-shadow: 0 0 0 0 {{ $look['from'] }}88; } 50% { box-shadow: 0 0 0 6px {{ $look['from'] }}00; } }
    @keyframes mnx-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-2px); } }
    @keyframes mnx-tap { 0%, 70%, 100% { transform: scale(1); } 80% { transform: scale(.85); } }
    @keyframes mnx-wobble { 0%, 100% { transform: rotate(0); } 20% { transform: rotate(-10deg); } 40% { transform: rotate(8deg); } 60% { transform: rotate(-4deg); } }
    @keyframes mnx-spin { to { transform: rotate(360deg); } }
    @keyframes mnx-sway { 0%, 100% { transform: translateX(-1.5px); } 50% { transform: translateX(1.5px); } }

    /* The menu: icons lift on hover; the open page's icon glows in the role's colour. */
    .fi-sidebar-item-icon { transition: transform .18s ease, color .18s ease, filter .18s ease; }
    .fi-sidebar-item-button:hover .fi-sidebar-item-icon { transform: translateY(-1px) scale(1.15); color: {{ $look['from'] }}; }
    .fi-sidebar-item-active .fi-sidebar-item-icon { color: {{ $look['from'] }} !important; filter: drop-shadow(0 0 4px {{ $look['from'] }}aa); animation: mnx-float 2.6s ease-in-out infinite; }
    .fi-sidebar-group-icon { transition: transform .2s ease; }
    .fi-sidebar-group-button:hover .fi-sidebar-group-icon { transform: rotate(-8deg) scale(1.1); }
    .fi-user-avatar { box-shadow: 0 0 0 2px #fff, 0 0 0 4px {{ $look['from'] }}; }

    @media (prefers-reduced-motion: reduce) {
        .mnx-role *, .mnx-role, .fi-sidebar-item-icon, .fi-sidebar-item-active .fi-sidebar-item-icon { animation: none !important; transition: none !important; }
        .mnx-m-shine::after { display: none; }
    }
</style>
<span class="mnx-role" title="Signed in as {{ $look['label'] }}" data-role-look="{{ $look['motion'] }}">
    <span class="mnx-role-icon mnx-m-{{ $look['motion'] }}"><x-filament::icon :icon="$look['icon']" /></span>
    <span class="mnx-role-label">{{ $look['label'] }}</span>
</span>
