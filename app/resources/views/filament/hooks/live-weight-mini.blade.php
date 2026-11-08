{{--
    The weighbridge's weight in the top bar of every screen, for whoever may see the Live weight screen: kept current
    in the browser (public/js/live-weight.js, twice a second), so it never redraws the page or a form being filled in.
    Pressing it opens the Live weight screen.
--}}
@php
    $scales = \App\Filament\Pages\LiveWeightPage::canAccess() && app(\App\Domain\Tenancy\TenantContext::class)->entityId() !== null
        ? \App\Models\Gate\Scale::query()->where('status', 'active')->orderBy('code')->limit(3)->get(['id', 'code', 'name'])
        : collect();
@endphp
@if ($scales->isNotEmpty())
    <style>
        .mnx-lw-bar { display: flex; gap: .4rem; align-items: center; margin-right: .5rem; }
        .mnx-lw-mini { display: inline-flex; align-items: center; gap: .4rem; padding: .3rem .65rem; border-radius: 9999px; font-size: .8rem; line-height: 1;
            background: rgba(0,0,0,.04); border: 1px solid rgba(0,0,0,.08); color: inherit; white-space: nowrap; }
        .dark .mnx-lw-mini { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.1); }
        .mnx-lw-mini:hover { border-color: rgb(var(--primary-500)); }
        .mnx-lw-mini [data-lw-mini="lamp"] { width: .55rem; height: .55rem; border-radius: 9999px; background: rgb(var(--gray-400)); flex: none; }
        .mnx-lw-mini [data-lw-mini="kg"] { font-family: ui-monospace, monospace; font-weight: 700; font-size: .9rem; }
        .mnx-lw-mini [data-lw-mini="state"] { color: rgb(var(--gray-500)); font-size: .7rem; }
        @media (max-width: 640px) { .mnx-lw-mini [data-lw-mini="code"], .mnx-lw-mini [data-lw-mini="state"] { display: none; } }
        @media print { .mnx-lw-bar { display: none !important; } }
    </style>
    <div class="mnx-lw-bar">
        @foreach ($scales as $scale)
            <a href="{{ \App\Filament\Pages\LiveWeightPage::getUrl() }}" class="mnx-lw-mini" wire:ignore
                data-live-weight-mini="{{ route('filament.admin.gate.live-weight', ['scale' => $scale->id]) }}" data-name="{{ $scale->name }}"
                title="{{ $scale->name }}: live weight">
                <span data-lw-mini="lamp" aria-hidden="true"></span>
                <span data-lw-mini="code">{{ $scale->code }}</span>
                <span data-lw-mini="kg">—</span><span>kg</span>
                <span data-lw-mini="state">…</span>
            </a>
        @endforeach
    </div>
@endif
