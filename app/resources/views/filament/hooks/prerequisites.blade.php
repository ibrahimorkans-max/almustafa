{{-- Before a new record: what must exist first, and what to have at hand (App\Domain\Assistant\Prerequisites). --}}
@php($checks = \App\Domain\Assistant\Prerequisites::for($record))
@if ($checks !== [])
    @php($ready = \App\Domain\Assistant\Prerequisites::ready($checks))
    <div class="mnx-prereq" style="margin-bottom:1rem; padding:.75rem 1rem; border-radius:.75rem; font-size:.875rem; line-height:1.5;
        border:1px solid {{ $ready ? 'rgb(var(--info-300, 147 197 253))' : 'rgb(var(--danger-300))' }};
        background: {{ $ready ? 'rgb(var(--info-50, 239 246 255))' : 'rgb(var(--danger-50))' }};">
        <div style="font-weight:600; margin-bottom:.25rem;">
            {{ $ready ? 'Before you add it' : 'Needed first: this cannot be saved until the ✗ items are added' }}
        </div>
        <ul style="margin:0; padding:0; list-style:none;">
            @foreach ($checks as $c)
                <li style="display:flex; gap:.5rem;">
                    <span style="flex:none; width:1rem; font-weight:700; color: {{ match ($c['state']) { 'ok' => 'rgb(var(--success-600))', 'missing' => 'rgb(var(--danger-600))', default => 'rgb(var(--gray-500))' } }};">
                        {{ match ($c['state']) { 'ok' => '✓', 'missing' => '✗', default => '•' } }}
                    </span>
                    <span>
                        {{ $c['text'] }}
                        @if ($c['state'] === 'missing' && filled($c['url'] ?? null))
                            <a href="{{ $c['url'] }}" style="font-weight:600; text-decoration:underline; color: rgb(var(--primary-600));">{{ $c['link'] }} →</a>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
    <style>.dark .mnx-prereq { background: rgba(255,255,255,.04) !important; border-color: rgba(255,255,255,.15) !important; }</style>
@endif
