<x-filament-panels::page>
    @php
        $hook = $this->getWebhook();
        $events = $this->getEvents();
        $th = 'px-2 py-1.5 text-left text-xs font-medium text-gray-500';
        $td = 'px-2 py-1.5 text-sm align-top';
    @endphp

    @if ($hook === null)
        <x-filament::section>Choose a mill first (top right: Switch entity / account).</x-filament::section>
    @else
        @unless ($this->getOwnNumber())
            <x-filament::section>
                <p class="text-sm text-gray-600 dark:text-gray-300">This mill sends from the product's WhatsApp number, whose webhook is set up by the product owner. Fill in the form below only if the mill connects its own number (Administration → Integrations) with its own Meta app.</p>
            </x-filament::section>
        @endunless

        <x-filament::section heading="Copy these into Meta → Configure Webhooks">
            <div class="space-y-4">
                @foreach ([['Callback URL', $hook['url']], ['Verify token', $hook['verify_token']]] as [$label, $value])
                    <div x-data="{ copied: false }">
                        <div class="mb-1 text-sm font-medium">{{ $label }}</div>
                        <div class="flex items-stretch gap-2">
                            <input type="text" readonly value="{{ $value }}" x-ref="field" x-on:focus="$event.target.select()"
                                class="block w-full min-w-0 rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5">
                            <x-filament::button color="gray" icon="heroicon-o-clipboard"
                                x-on:click="$refs.field.select(); (navigator.clipboard ? navigator.clipboard.writeText($refs.field.value) : Promise.reject()).catch(() => document.execCommand('copy') || Promise.reject()).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})">
                                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach

                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium">App secret:</span>
                    @if ($hook['has_secret'])
                        <x-filament::badge color="success">Saved</x-filament::badge>
                    @else
                        <x-filament::badge color="danger">Not saved: WhatsApp's messages are refused until it is</x-filament::badge>
                    @endif
                </div>

                <ol class="list-decimal space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-300">
                    <li>In Meta for Developers open your app → WhatsApp → Configuration (or "Configure Webhooks").</li>
                    <li>Paste the <strong>Callback URL</strong> and the <strong>Verify token</strong> above, and press <strong>Verify and save</strong>. Leave "Attach a client certificate" off.</li>
                    <li>Under <strong>Webhook fields</strong>, press <strong>Manage</strong> and subscribe to <strong>messages</strong>.</li>
                    <li>Press <strong>Save app secret</strong> (top right) and paste the app secret from App settings → Basic.</li>
                    <li>Publish the app (Meta sends real messages only to a published app). Then send a message: its delivery shows below and under Masters → Party messages.</li>
                </ol>
                @if (! str_starts_with($hook['url'], 'https://'))
                    <p class="text-sm text-danger-600">Meta accepts only an https address reachable from the internet. This address is {{ parse_url($hook['url'], PHP_URL_SCHEME) }}: use the system's public https address (APP_URL on the server).</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="What WhatsApp sent back" :description="$events->isEmpty() ? 'Nothing yet.' : 'The latest '.$events->count().'.'">
            @if ($events->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead><tr><th class="{{ $th }}">When</th><th class="{{ $th }}">What</th><th class="{{ $th }}">Party / number</th><th class="{{ $th }}">Message</th></tr></thead>
                        <tbody>
                            @foreach ($events as $e)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="{{ $td }} whitespace-nowrap">{{ $e->occurred_at->timezone('Asia/Karachi')->format('d M Y H:i') }}</td>
                                    <td class="{{ $td }}">
                                        <x-filament::badge :color="match ($e->status) { 'read' => 'success', 'delivered' => 'info', 'failed' => 'danger', 'received' => 'warning', default => 'gray' }">
                                            {{ $e->kind === 'message' ? 'Message received' : \App\Models\WhatsAppEvent::PROGRESS[$e->status] ?? $e->status }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="{{ $td }}">{{ $e->party?->name ?? '—' }}<div class="font-mono text-xs text-gray-500">{{ $e->phone }}</div></td>
                                    <td class="{{ $td }}">{{ $e->body ?? ($e->kind === 'message' ? '('.$e->type.')' : '') }}@if ($e->error)<div class="text-xs text-danger-600">{{ $e->error }}</div>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
