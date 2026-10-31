<x-filament-panels::page>
    @php
        $chats = $this->getConversations();
        $thread = $this->getThread();
        $head = $this->getChatHeader();
        $what = ['login' => 'Login details', 'money' => 'Account entry', 'unloaded' => 'Vehicle unloaded', 'weighed' => 'Vehicle weights', 'document' => 'File'];
    @endphp
    {{-- Its own small styles: the panel's stylesheet has only the classes Filament itself uses. --}}
    <style>
        .rm-chats { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) { .rm-chats { grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); } }
        .rm-chat-item { display: block; width: 100%; text-align: left; border-radius: .5rem; padding: .5rem .75rem; margin-bottom: .25rem; }
        .rm-chat-item:hover { background: rgba(var(--gray-500), .08); }
        .rm-chat-item.on { background: rgba(var(--primary-500), .12); box-shadow: inset 0 0 0 1px rgba(var(--primary-500), .35); }
        .rm-thread { max-height: 70vh; overflow-y: auto; display: flex; flex-direction: column; gap: .5rem; padding-right: .25rem; }
        .rm-bubble { max-width: 85%; border-radius: 1rem; padding: .5rem .75rem; font-size: .875rem; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .rm-out { align-self: flex-end; background: #dcfce7; color: #111827; }
        .rm-in { align-self: flex-start; background: #ffffff; color: #111827; box-shadow: inset 0 0 0 1px #e5e7eb; }
        .dark .rm-out { background: rgba(22, 101, 52, .45); color: #f3f4f6; }
        .dark .rm-in { background: rgba(255,255,255,.08); color: #f3f4f6; box-shadow: none; }
        .rm-meta { margin-top: .25rem; font-size: 11px; color: #6b7280; text-align: right; }
        .rm-kind { font-size: 12px; font-weight: 600; color: #047857; margin-bottom: .125rem; }
        .rm-count { flex-shrink: 0; border-radius: 9999px; padding: 0 .4rem; font-size: 12px; background: rgba(var(--gray-500), .12); }
        .rm-text { white-space: pre-line; overflow-wrap: anywhere; }
    </style>
    <div class="rm-chats">
        <x-filament::section :heading="'Conversations ('.count($chats).')'">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search party, code or number"
                class="mb-3 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
            @forelse ($chats as $c)
                <button type="button" wire:click="open('{{ $c['key'] }}')" wire:key="chat-{{ $c['key'] }}" @class(['rm-chat-item', 'on' => $this->chat === $c['key']])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-medium">{{ $c['name'] }}</span>
                        <span class="text-xs text-gray-500" style="flex-shrink: 0;">{{ $c['last']->timezone('Asia/Karachi')->format($c['last']->isToday() ? 'H:i' : 'd M') }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-xs text-gray-500">{{ $c['preview'] }}</span>
                        <span class="rm-count">{{ $c['count'] }}</span>
                    </div>
                </button>
            @empty
                <p class="text-sm text-gray-500">{{ $this->search !== '' ? 'Nothing matches.' : 'No messages yet. Messages to parties are switched on under Party messages.' }}</p>
            @endforelse
        </x-filament::section>

        <x-filament::section :heading="$head ? $head['name'] : 'Choose a conversation'" :description="$head['phone'] ?? null">
            @if ($head === null)
                <p class="text-sm text-gray-500">Every message sent to a party shows here (WhatsApp or SMS, with how it went), and what parties send back on WhatsApp.</p>
            @else
                <div class="rm-thread" x-data x-init="$el.scrollTop = $el.scrollHeight">
                    @forelse ($thread as $m)
                        <div @class(['rm-bubble', 'rm-out' => $m['out'], 'rm-in' => ! $m['out']])>
                            @if ($m['out'] && ($m['what'] ?? null))
                                <div class="rm-kind">{{ $what[$m['what']] ?? ucfirst((string) $m['what']) }}</div>
                            @endif
                            <div class="rm-text">{{ $m['text'] }}</div>
                            <div class="rm-meta">
                                {{ $m['at']->timezone('Asia/Karachi')->format('d M Y H:i') }}
                                @if ($m['out'])
                                    · {{ $m['channel'] === 'sms' ? 'SMS' : 'WhatsApp' }} ·
                                    @if ($m['delivery'] === 'read') <span style="color: #0284c7;">✓✓ Read</span>
                                    @elseif ($m['delivery'] === 'delivered') ✓✓ Delivered
                                    @elseif ($m['delivery'] === 'failed') <span style="color: #dc2626;">Not delivered</span>
                                    @elseif ($m['status'] === 'sent') ✓ Sent
                                    @elseif ($m['status'] === 'manual') <span style="color: #d97706;">To send by hand</span>
                                    @elseif ($m['status'] === 'failed') <span style="color: #dc2626;">Failed</span>
                                    @elseif ($m['status'] === 'skipped') Not sent
                                    @else {{ $m['status'] }}
                                    @endif
                                @else
                                    · received on WhatsApp
                                @endif
                            </div>
                            @if ($m['out'] && $m['error'])
                                <div class="rm-meta" style="color: #dc2626;">{{ $m['error'] }}</div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No messages.</p>
                    @endforelse
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
