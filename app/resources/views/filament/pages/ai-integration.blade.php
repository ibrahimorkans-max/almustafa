<x-filament-panels::page>
    @php($s = $this->getState())
    @if ($s['scope'] === null)
        <x-filament::section>Choose a mill first (top right: Switch entity / account).</x-filament::section>
    @else
        <x-filament::section heading="Status">
            <div class="space-y-2 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    @switch($s['source'])
                        @case('mill')
                            <x-filament::badge color="success">Connected: the mill's own key</x-filament::badge>
                            @break
                        @case('product')
                            <x-filament::badge color="{{ $s['scope'] === 'platform' ? 'success' : 'info' }}">{{ $s['scope'] === 'platform' ? 'Connected: the product\'s key (for every mill without its own)' : 'Using the product\'s key' }}</x-filament::badge>
                            @break
                        @case('server')
                            <x-filament::badge color="info">Using the server's key (.env)</x-filament::badge>
                            @break
                        @default
                            <x-filament::badge color="gray">No key: the Assistant answers only short commands</x-filament::badge>
                    @endswitch
                </div>
                @if ($s['source'] !== null)
                    <p><span class="font-medium">Provider:</span> {{ $s['provider'] }} · <span class="font-medium">Model:</span> {{ $s['model_label'] }}</p>
                @endif
                @if ($s['own'])
                    <p class="text-xs text-gray-500">Key saved, encrypted: …{{ $s['own']['key_end'] }}@if ($s['own']['saved_at']) · saved {{ \Carbon\Carbon::parse($s['own']['saved_at'])->timezone('Asia/Karachi')->format('d M Y H:i') }}@endif</p>
                @endif
                @unless ($s['enabled'])
                    <p class="text-danger-600">The Assistant is switched off on this server (RICEMILL_ASSISTANT=false).</p>
                @endunless
            </div>
        </x-filament::section>

        <x-filament::section heading="What it is used for">
            <ul class="list-disc space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-300">
                <li><strong>Assistant</strong> (top menu): understands normal sentences in English or Urdu, looks things up and prepares changes for you to confirm. Only the question, earlier turns and what it looked up are sent to the AI provider.</li>
                <li><strong>Reading roznamcha pages</strong>: {{ $s['ocr_ai'] ? 'photos and scans of the page are read completely by the AI (both sides, handwriting, Urdu).' : 'pages are read on this server (printed English only; the AI reader is switched off on the server).' }}</li>
            </ul>
        </x-filament::section>

        <x-filament::section heading="How to get a key">
            <ol class="list-decimal space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-300">
                <li>Choose a provider and open its console, e.g. <span class="font-mono">openrouter.ai</span> (one key for Claude, GPT, Gemini, Llama and more), <span class="font-mono">console.anthropic.com</span>, <span class="font-mono">platform.openai.com</span> or <span class="font-mono">aistudio.google.com</span>. Add credit if it asks.</li>
                <li>Create an <strong>API key</strong>, name it e.g. "Rice mill", and copy it.</li>
                <li>Press <strong>Add API key</strong> above, choose the provider, pick or type the model and paste the key. It is checked before it is saved.</li>
            </ol>
            <p class="mt-2 text-xs text-gray-500">Each question costs a little from the provider account's credit. For roznamcha photos choose a model that reads pictures (Claude, GPT-4o/5, Gemini, Pixtral…). Never share the key; if it leaks, delete it in the console and add a new one here.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
