<x-filament-panels::page>
    <div class="flex flex-col gap-3">
        <div class="text-xs text-gray-500">
            {{ $this->aiEnabled() ? 'AI mode: normal sentences in English or Roman Urdu.' : 'Command mode (offline). Add an Anthropic API key to ask in normal sentences.' }}
            Anything that changes data is shown first and runs only when you press Confirm.
        </div>

        <div class="flex flex-col gap-3" id="assistant-messages">
            @foreach ($messages as $i => $m)
                <div @class(['flex', 'justify-end' => $m['role'] === 'user'])>
                    <div @class([
                        'max-w-3xl rounded-xl px-4 py-3 text-sm shadow-sm',
                        'bg-primary-600 text-white' => $m['role'] === 'user',
                        'bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => $m['role'] !== 'user',
                    ])>
                        <div class="whitespace-pre-line">{{ $m['text'] }}</div>

                        @if (! empty($m['table']))
                            <div class="mt-2 overflow-x-auto">
                                <table class="min-w-full text-xs">
                                    <thead><tr>
                                        @foreach ($m['table']['columns'] as $col)
                                            <th class="border-b border-gray-200 px-2 py-1 text-left font-semibold dark:border-white/10">{{ $col }}</th>
                                        @endforeach
                                    </tr></thead>
                                    <tbody>
                                        @foreach ($m['table']['rows'] as $row)
                                            <tr>
                                                @foreach ($row as $cell)
                                                    <td class="border-b border-gray-100 px-2 py-1 dark:border-white/5">{{ $cell }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if (! empty($m['links']))
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($m['links'] as $link)
                                    <a href="{{ $link['url'] }}" class="rounded-lg bg-gray-100 px-2 py-1 text-xs font-medium text-primary-700 hover:bg-gray-200 dark:bg-white/5 dark:text-primary-400" @if (str_contains($link['url'], '/print/')) target="_blank" @endif>{{ $link['label'] }} →</a>
                                @endforeach
                            </div>
                        @endif

                        @if (! empty($m['pending']))
                            <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-gray-900 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-white">
                                <div class="font-medium">{{ $m['pending']['summary'] }}</div>
                                @if (empty($m['done']))
                                    <div class="mt-2 flex gap-2">
                                        <x-filament::button size="sm" color="success" wire:click="confirm({{ $i }})" icon="heroicon-o-check">Confirm</x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="cancel({{ $i }})">Cancel</x-filament::button>
                                    </div>
                                @else
                                    <div class="mt-1 text-xs text-gray-500">Answered.</div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
            <div wire:loading wire:target="send,confirm" class="text-xs text-gray-500">Working…</div>
        </div>

        <form wire:submit="send" class="sticky bottom-0 flex gap-2 bg-gray-50 py-2 dark:bg-gray-950">
            <input type="text" wire:model="message" maxlength="1000" autocomplete="off" autofocus
                placeholder="{{ $this->aiEnabled() ? 'e.g. How much profit this month? · Register MNA-21-1234 for sauda 0001' : 'e.g. stock · profit this month · arrived MNA-21-1234 · help' }}"
                class="flex-1 rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane">Send</x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
