{{-- This person's chats, day by day: open one to read it and carry on with it. --}}
<div class="mnx-history">
    <style>
        .mnx-history { display: flex; flex-direction: column; gap: .9rem; font-size: .85rem; }
        .mnx-history h3 { font-weight: 700; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: rgb(var(--gray-500)); margin-bottom: .35rem; }
        .mnx-history button.mnx-chat-row { width: 100%; display: flex; justify-content: space-between; align-items: center; gap: .75rem; text-align: left;
            padding: .55rem .7rem; margin-bottom: .35rem; border-radius: .55rem; cursor: pointer; color: inherit;
            background: #fff; border: 1px solid rgba(0,0,0,.07); }
        .dark .mnx-history button.mnx-chat-row { background: rgb(var(--gray-900)); border-color: rgba(255,255,255,.08); }
        .mnx-history button.mnx-chat-row:hover, .mnx-history button.mnx-chat-row[aria-current="true"] { border-color: rgb(var(--primary-500)); }
        .mnx-history .mnx-chat-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
        .mnx-history .mnx-chat-meta { flex: none; font-size: .72rem; color: rgb(var(--gray-500)); }
    </style>

    <div style="display:flex; justify-content:space-between; align-items:center; gap:.5rem;">
        <span style="color: rgb(var(--gray-500)); font-size: .8rem;">Your chats of the last {{ \App\Models\Assistant\AssistantChat::KEEP_DAYS }} days. Open one to carry on with it.</span>
        <x-filament::button size="xs" color="gray" icon="heroicon-o-plus" wire:click="clearChat">New chat</x-filament::button>
    </div>

    @forelse ($this->history() as $day => $chats)
        <section>
            <h3>{{ $day }}</h3>
            @foreach ($chats as $c)
                <button type="button" class="mnx-chat-row" wire:click="openChat('{{ $c['id'] }}')" aria-current="{{ $c['current'] ? 'true' : 'false' }}">
                    <span class="mnx-chat-title" dir="auto">{{ $c['title'] }}</span>
                    <span class="mnx-chat-meta">{{ $c['time'] }} · {{ $c['count'] }} messages{{ $c['current'] ? ' · open' : '' }}</span>
                </button>
            @endforeach
        </section>
    @empty
        <p style="color: rgb(var(--gray-500));">No chats yet. Ask something in the Chat tab; it is kept here day by day.</p>
    @endforelse
</div>
