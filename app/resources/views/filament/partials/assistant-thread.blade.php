{{-- The conversation (App\Livewire\Concerns\TalksToAssistant): the Assistant page and the floating chat. --}}
<style>
    .mnx-row { display: flex; align-items: flex-end; gap: .5rem; }
    .mnx-row.mnx-me { justify-content: flex-end; }
    .mnx-avatar { flex: none; width: 2rem; height: 2rem; border-radius: 9999px; display: flex; align-items: center; justify-content: center; color: #fff;
        background: linear-gradient(135deg, rgb(var(--primary-500)), #6366f1); box-shadow: 0 2px 6px rgba(0,0,0,.18); }
    .mnx-avatar svg { width: 1.1rem; height: 1.1rem; }
    .mnx-bubble { max-width: 48rem; padding: .7rem 1rem; font-size: .875rem; line-height: 1.45; border-radius: 1.1rem; }
    .mnx-bot .mnx-bubble { background: rgb(var(--gray-100)); color: rgb(var(--gray-900)); border-bottom-left-radius: .35rem; }
    .dark .mnx-bot .mnx-bubble { background: rgb(var(--gray-800)); color: rgb(var(--gray-100)); }
    .mnx-me .mnx-bubble { background: rgb(var(--primary-600)); color: #fff; border-bottom-right-radius: .35rem; }
    .mnx-pills { display: flex; flex-wrap: wrap; gap: .5rem; margin: .65rem 0 .25rem 2.5rem; }
</style>

@foreach ($messages as $i => $m)
    @php($bot = $m['role'] !== 'user')
    <div @class(['mnx-row', 'mnx-bot' => $bot, 'mnx-me' => ! $bot]) wire:key="msg-{{ $i }}">
        @if ($bot)
            <span class="mnx-avatar" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
            </span>
        @endif
        <div class="mnx-bubble">
            <div class="whitespace-pre-line" dir="auto">{{ $m['text'] }}</div>

            @if (! empty($m['table']))
                <div class="mt-2 overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead><tr>
                            @foreach ($m['table']['columns'] as $col)
                                <th class="border-b border-gray-300 px-2 py-1 text-left font-semibold dark:border-white/10">{{ $col }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @foreach ($m['table']['rows'] as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td class="border-b border-gray-200 px-2 py-1 dark:border-white/5" dir="auto">{{ $cell }}</td>
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
                        <a href="{{ $link['url'] }}" class="rounded-lg bg-white px-2 py-1 text-xs font-medium text-primary-700 hover:bg-gray-50 dark:bg-white/5 dark:text-primary-400" @if (str_contains($link['url'], '/print/')) target="_blank" @endif>{{ $link['label'] }} →</a>
                    @endforeach
                </div>
            @endif

            @if (! empty($m['pending']))
                <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-gray-900 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-white">
                    <div class="font-medium" dir="auto">{{ $m['pending']['summary'] }}</div>
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

    @if ($i === 0 && $bot)
        {{-- Every feature as a button: a quick form, a question asked at once, or an example to finish. --}}
        <div class="mnx-pills">
            @foreach ($this->starters() as $s)
                <button type="button" class="mnx-pill" title="{{ $s['hint'] }}" wire:click="start(@js($s['kind']), @js($s['value']))"><span>{{ $s['label'] }}</span></button>
            @endforeach
        </div>
    @endif
@endforeach
<div wire:loading wire:target="send,confirm,quick,start" class="text-xs text-gray-500" style="margin-left:2.5rem;">Working…</div>
