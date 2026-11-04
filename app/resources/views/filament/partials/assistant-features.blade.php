{{-- Everything the assistant can do for this person: press an example to put it in the box, or a quick button. --}}
<div class="mnx-features">
    <style>
        .mnx-features { display: flex; flex-direction: column; gap: 1rem; font-size: .85rem; }
        .mnx-features h3 { font-weight: 700; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: rgb(var(--gray-500)); margin-bottom: .4rem; }
        .mnx-feature { padding: .65rem .75rem; border-radius: .6rem; background: #fff; border: 1px solid rgba(0,0,0,.07); margin-bottom: .45rem; }
        .dark .mnx-feature { background: rgb(var(--gray-900)); border-color: rgba(255,255,255,.08); }
        .mnx-feature-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-weight: 600; }
        .mnx-feature p { margin-top: .2rem; color: rgb(var(--gray-600)); font-size: .8rem; line-height: 1.4; }
        .dark .mnx-feature p { color: rgb(var(--gray-400)); }
        .mnx-chip { display: inline-block; margin: .35rem .3rem 0 0; padding: .15rem .5rem; border-radius: 9999px; border: 1px solid rgba(0,0,0,.12);
            background: rgba(0,0,0,.03); font-size: .75rem; cursor: pointer; color: inherit; text-align: left; }
        .mnx-chip:hover { border-color: rgb(var(--primary-500)); color: rgb(var(--primary-700)); }
        .dark .mnx-chip { border-color: rgba(255,255,255,.15); background: rgba(255,255,255,.04); }
        .mnx-badge { font-size: .65rem; font-weight: 600; padding: .05rem .4rem; border-radius: 9999px; background: rgb(var(--warning-100)); color: rgb(var(--warning-800)); white-space: nowrap; }
        .mnx-screens { display: flex; flex-wrap: wrap; gap: .3rem; }
        .mnx-screens a { font-size: .75rem; padding: .15rem .5rem; border-radius: .4rem; background: rgba(0,0,0,.04); color: rgb(var(--primary-700)); }
        .dark .mnx-screens a { background: rgba(255,255,255,.05); color: rgb(var(--primary-400)); }
    </style>

    <p style="color: rgb(var(--gray-500)); font-size: .8rem;">
        {{ $this->aiEnabled() ? 'Ask in your own words (English or Roman Urdu); these are examples.' : 'Type these commands (add an AI key to ask in your own words).' }}
        Press an example to put it in the box. Anything marked <span class="mnx-badge">Confirm</span> changes data and runs only after you confirm it.
    </p>

    @foreach ($this->features() as $group)
        <section>
            <h3>{{ $group['group'] }}</h3>
            @foreach ($group['items'] as $f)
                <div class="mnx-feature">
                    <div class="mnx-feature-head">
                        <span>{{ $f['title'] }}</span>
                        <span style="display:flex; gap:.35rem; align-items:center;">
                            @if ($f['writes'])
                                <span class="mnx-badge">Confirm</span>
                            @endif
                            @if ($f['quick'])
                                <button type="button" class="mnx-pill mnx-pill-sm" wire:click="quick('{{ $f['quick'] }}')"><span>Fill the blanks</span></button>
                            @endif
                        </span>
                    </div>
                    <p>{{ $f['description'] }}</p>
                    @foreach ($f['examples'] as $example)
                        <button type="button" class="mnx-pill mnx-pill-sm" style="margin:.4rem .3rem 0 0;" wire:click="tryExample(@js($example))"><span>{{ $example }}</span></button>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endforeach

    @php($screens = $this->screens())
    @if ($screens !== [])
        <section>
            <h3>Screens you can open</h3>
            @foreach ($screens as $menu => $links)
                <div style="margin-bottom:.5rem;">
                    <div style="font-weight:600; font-size:.78rem; margin-bottom:.2rem;">{{ $menu }}</div>
                    <div class="mnx-screens">
                        @foreach ($links as $link)
                            <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>
    @endif
</div>
