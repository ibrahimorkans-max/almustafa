<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Which messages go to parties</x-slot>
        <x-slot name="description">Each switch is saved at once, for this firm. A single party can be switched off on its own page (Send messages to this party).</x-slot>
        <div class="space-y-3">
            @foreach (\App\Domain\Settings\SettingsService::MESSAGE_EVENTS as $event => $label)
                <label class="flex items-center gap-3 text-sm">
                    <input type="checkbox" wire:model.live="events.{{ $event }}" class="rounded border-gray-300 text-primary-600">
                    <span>{{ $label }}</span>
                </label>
            @endforeach
            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-3 text-sm dark:border-white/10">
                <span class="text-gray-500">Send by</span>
                <label class="flex items-center gap-1"><input type="radio" wire:model.live="channel" value="whatsapp" class="text-primary-600"> WhatsApp</label>
                <label class="flex items-center gap-1"><input type="radio" wire:model.live="channel" value="sms" class="text-primary-600"> SMS</label>
                @if ($this->gatewayReady())
                    <x-filament::badge color="success">Sent automatically by the gateway</x-filament::badge>
                @else
                    <x-filament::badge color="warning">No gateway yet: each message waits below for sending by hand</x-filament::badge>
                @endif
            </div>
            <label class="flex items-center gap-3 border-t border-gray-200 pt-3 text-sm dark:border-white/10">
                <input type="checkbox" wire:model.live="push" class="rounded border-gray-300 text-primary-600">
                <span>Also as a notification on the phones and browsers parties turned notifications on for (Firebase)</span>
            </label>
            @php($c = $this->connections())
            <div class="flex flex-wrap gap-2 text-sm">
                <x-filament::badge :color="$c['whatsapp'] ? 'success' : 'gray'">WhatsApp Business: {{ $c['whatsapp'] ? 'connected' : 'not set up' }}</x-filament::badge>
                <x-filament::badge :color="$c['firebase'] ? 'success' : 'gray'">Firebase notifications: {{ $c['firebase'] ? 'connected' : 'not set up' }}</x-filament::badge>
                @if ($c['firebase'] && ! $c['firebase_web'])
                    <x-filament::badge color="warning">Firebase web app settings missing: parties cannot turn notifications on yet</x-filament::badge>
                @endif
            </div>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
