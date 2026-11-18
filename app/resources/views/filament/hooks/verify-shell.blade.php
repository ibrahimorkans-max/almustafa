{{--
    The sign-in and verification screens' shell:
      - a moving background (soft aurora orbs, a slowly drifting grid, a light beam and rising particles) behind the
        sign-in pages and every security check;
      - during a check (LoginAccount::inSecurityCheck) no sidebar, top bar or other feature is shown — only the check,
        the language and "Sign out".
    Everything moves only with transform/opacity, never under the pointer, and stands still under reduced motion.
--}}
@php
    $user = \Filament\Facades\Filament::auth()->user();
    $checking = $user instanceof \App\Models\LoginAccount && $user->inSecurityCheck();
    $guest = ! $user instanceof \App\Models\LoginAccount;
@endphp
@if ($checking || $guest)
    <style>
        .rmv-bg { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none;
            background: radial-gradient(1200px 700px at 10% -10%, rgba(79, 125, 255, .10), transparent 60%),
                        radial-gradient(900px 600px at 110% 110%, rgba(16, 185, 129, .10), transparent 60%), #f6f8fc; }
        .dark .rmv-bg { background: radial-gradient(1200px 700px at 10% -10%, rgba(79, 125, 255, .18), transparent 60%),
                        radial-gradient(900px 600px at 110% 110%, rgba(16, 185, 129, .12), transparent 60%), #0b0d12; }
        .fi-body { background: transparent !important; }
        .fi-layout, .fi-simple-layout, .rmv-shell-bar { position: relative; z-index: 1; }
        /* Aurora: three soft orbs drifting slowly (20–30 s): alive, never distracting. */
        .rmv-orb { position: absolute; width: 42vmax; height: 42vmax; border-radius: 9999px; filter: blur(60px); opacity: .35; will-change: transform; }
        .rmv-orb.a { left: -12vmax; top: -14vmax; background: rgb(79, 125, 255); animation: rmv-drift-a 26s ease-in-out infinite alternate; }
        .rmv-orb.b { right: -14vmax; top: 20vh; background: rgb(16, 185, 129); opacity: .25; animation: rmv-drift-b 30s ease-in-out infinite alternate; }
        .rmv-orb.c { left: 25vw; bottom: -20vmax; background: rgb(56, 189, 248); opacity: .22; animation: rmv-drift-c 22s ease-in-out infinite alternate; }
        .dark .rmv-orb { opacity: .28; }
        /* A faint dot grid panning slowly: the "secure terminal" texture. */
        .rmv-grid { position: absolute; inset: -40px; opacity: .45;
            background-image: radial-gradient(rgba(79, 125, 255, .22) 1px, transparent 1.2px); background-size: 28px 28px;
            -webkit-mask: radial-gradient(ellipse at center, #000 30%, transparent 75%); mask: radial-gradient(ellipse at center, #000 30%, transparent 75%);
            animation: rmv-pan 40s linear infinite; }
        /* A light beam crossing now and then (every 9 s): a quiet "scanning" heartbeat. */
        .rmv-beam { position: absolute; top: 0; bottom: 0; width: 30vw; left: -35vw;
            background: linear-gradient(90deg, transparent, rgba(79, 125, 255, .10), transparent); transform: skewX(-18deg);
            animation: rmv-beam 9s cubic-bezier(0.16, 1, 0.3, 1) infinite; }
        /* Particles rising slowly. */
        .rmv-dot { position: absolute; bottom: -12px; width: 4px; height: 4px; border-radius: 9999px; background: rgba(79, 125, 255, .45);
            box-shadow: 0 0 8px rgba(79, 125, 255, .6); animation: rmv-rise linear infinite; }
        @keyframes rmv-drift-a { to { transform: translate(14vw, 10vh) scale(1.1); } }
        @keyframes rmv-drift-b { to { transform: translate(-12vw, -8vh) scale(.92); } }
        @keyframes rmv-drift-c { to { transform: translate(10vw, -12vh) scale(1.08); } }
        @keyframes rmv-pan { to { transform: translate(28px, 28px); } }
        @keyframes rmv-beam { 0%, 55% { transform: translateX(0) skewX(-18deg); opacity: 0; } 60% { opacity: 1; } 100% { transform: translateX(170vw) skewX(-18deg); opacity: 0; } }
        @keyframes rmv-rise { 0% { transform: translateY(0); opacity: 0; } 10% { opacity: .9; } 100% { transform: translateY(-105vh); opacity: 0; } }
        @media (prefers-reduced-motion: reduce) { .rmv-bg * { animation: none !important; } .rmv-beam, .rmv-dot { display: none; } }
    </style>
    <div class="rmv-bg" aria-hidden="true">
        <span class="rmv-orb a"></span><span class="rmv-orb b"></span><span class="rmv-orb c"></span>
        <span class="rmv-grid"></span><span class="rmv-beam"></span>
        @foreach ([[6, 14, 0], [14, 18, 3], [23, 12, 7], [31, 20, 1], [42, 16, 5], [51, 13, 9], [60, 19, 2], [68, 15, 6], [77, 17, 4], [85, 12, 8], [93, 21, 10]] as [$x, $secs, $delay])
            <span class="rmv-dot" style="left: {{ $x }}%; animation-duration: {{ $secs }}s; animation-delay: -{{ $delay }}s"></span>
        @endforeach
    </div>
@endif

@if ($checking)
    {{-- During a security check: nothing of the system is shown — no menu, no top bar, no search, no other feature. --}}
    <style>
        .fi-sidebar, .fi-sidebar-close-overlay, .fi-topbar, .fi-topbar-ctn, .fi-breadcrumbs, .fi-global-search, .mnx-assistant { display: none !important; }
        .fi-main-ctn, .fi-main-ctn-sidebar-open { padding-inline-start: 0 !important; margin-inline-start: 0 !important; width: 100% !important; }
        .fi-main { max-width: 56rem !important; }
        .rmv-shell-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .9rem 1.25rem; max-width: 56rem; margin: 0 auto; }
        .rmv-shell-brand { display: flex; align-items: center; gap: .6rem; font-weight: 700; color: rgb(15, 76, 58); }
        .dark .rmv-shell-brand { color: #e6e9f2; }
        .rmv-shell-brand img { height: 2rem; width: auto; }
    </style>
    <div class="rmv-shell-bar">
        <span class="rmv-shell-brand">
            <img src="{{ asset(config('ricemill.product.logo')) }}" alt="{{ config('ricemill.product.name') }}">
            <span>{{ __('Security check') }}</span>
        </span>
        <span class="flex items-center gap-3">
            @include('filament.hooks.language-switch')
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                @csrf
                <x-filament::button type="submit" size="sm" color="gray" outlined icon="heroicon-o-arrow-right-start-on-rectangle">{{ __('Sign out') }}</x-filament::button>
            </form>
        </span>
    </div>
@endif
