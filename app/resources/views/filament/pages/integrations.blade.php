<x-filament-panels::page>
    @php($s = $this->getState())
    @if (! $s['mill_chosen'])
        <x-filament::section>Choose a mill first (top right: Switch entity / account): each mill connects its own WhatsApp.</x-filament::section>
    @else
        <x-filament::section heading="WhatsApp">
            <div class="space-y-2 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    @switch($s['source'])
                        @case('mill')
                            <x-filament::badge color="success">Connected: the mill's own number</x-filament::badge>
                            @break
                        @case('product')
                            <x-filament::badge color="info">Using the product's WhatsApp</x-filament::badge>
                            @break
                        @case('server')
                            <x-filament::badge color="info">Using the server's WhatsApp settings</x-filament::badge>
                            @break
                        @default
                            <x-filament::badge color="gray">Not connected</x-filament::badge>
                    @endswitch
                    @if ($s['number'])
                        <span class="font-medium">{{ $s['number'] }}</span>
                    @endif
                </div>
                <p class="text-gray-500">Messages to parties (login details, money entries, vehicles unloaded and weighed) go by WhatsApp when it is connected; otherwise they wait under Masters → Party messages to be sent by hand. Which messages go out is set there.</p>
                @if ($s['own'])
                    <p class="text-xs text-gray-500">Phone number ID {{ $s['own']['phone_number_id'] }} · template "{{ $s['own']['template'] ?? '' }}" ({{ $s['own']['language'] ?? '' }}) · token saved, encrypted.</p>
                @endif
            </div>
        </x-filament::section>
        <x-filament::section heading="Scanner (roznamcha pages)">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                @if (! $s['scanner_possible'])
                    <x-filament::badge color="gray">Not on this server</x-filament::badge>
                    <span class="text-gray-500">The server is not on the mill's network, so it cannot reach a scanner. Scan to a file and choose "Image" or "PDF" when adding a roznamcha page.</span>
                @elseif ($s['scanner'])
                    <x-filament::badge color="success">Connected</x-filament::badge><span>{{ $s['scanner'] }}</span>
                @else
                    <x-filament::badge color="gray">No scanner</x-filament::badge>
                    <span class="text-gray-500">Give the scanner's address with the Scanner button above.</span>
                @endif
            </div>
        </x-filament::section>
        <x-filament::section heading="Notifications on phones (Firebase)">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <x-filament::badge :color="$s['push'] ? 'success' : 'gray'">{{ $s['push'] ? 'On' : 'Not set up' }}</x-filament::badge>
                <span class="text-gray-500">{{ $s['push'] ? ($s['push_web'] ? 'Parties can turn notifications on in their app and browser.' : 'Parties\' apps get notifications; browser notifications need the web settings.') : 'Set up by the product owner for every mill.' }}</span>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
