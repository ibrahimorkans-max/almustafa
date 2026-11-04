{{-- Chat · Features · History (App\Livewire\Concerns\TalksToAssistant). --}}
<div class="mnx-tabs" role="tablist">
    <style>
        .mnx-tabs { display: flex; gap: .25rem; padding: .2rem; border-radius: .6rem; background: rgba(0,0,0,.05); }
        .dark .mnx-tabs { background: rgba(255,255,255,.06); }
        .mnx-tabs button { flex: 1; display: flex; align-items: center; justify-content: center; gap: .35rem; padding: .35rem .5rem;
            border: 0; border-radius: .45rem; background: none; cursor: pointer; font-size: .8rem; font-weight: 600; color: rgb(var(--gray-600)); }
        .dark .mnx-tabs button { color: rgb(var(--gray-300)); }
        .mnx-tabs button[aria-selected="true"] { background: #fff; color: rgb(var(--primary-700)); box-shadow: 0 1px 2px rgba(0,0,0,.12); }
        .dark .mnx-tabs button[aria-selected="true"] { background: rgb(var(--gray-800)); color: rgb(var(--primary-400)); }
        .mnx-tabs svg { width: 1rem; height: 1rem; }
    </style>
    @foreach (['chat' => ['Chat', 'heroicon-o-chat-bubble-left-right'], 'features' => ['Features', 'heroicon-o-squares-2x2'], 'history' => ['History', 'heroicon-o-clock']] as $key => [$label, $icon])
        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="showTab('{{ $key }}')">
            <x-filament::icon :icon="$icon" />{{ $label }}
        </button>
    @endforeach
</div>
