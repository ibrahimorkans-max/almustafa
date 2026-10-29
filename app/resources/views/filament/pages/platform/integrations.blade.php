<x-filament-panels::page>
    @php($s = $this->getState())
    @php($label = fn (?string $source) => match ($source) { 'product' => 'Connected here', 'server' => 'From the server (.env)', 'mill' => 'Connected', default => 'Not connected' })
    <x-filament::section heading="WhatsApp (messages to parties)">
        <div class="space-y-1 text-sm">
            <x-filament::badge :color="$s['whatsapp'] ? 'success' : 'gray'">{{ $label($s['whatsapp']) }}</x-filament::badge>
            @if ($s['whatsapp_saved'])
                <p>{{ $s['whatsapp_saved']['business_number'] ?? '' }} · phone number ID {{ $s['whatsapp_saved']['phone_number_id'] }} · template "{{ $s['whatsapp_saved']['template'] ?? '' }}"</p>
            @endif
            <p class="text-gray-500">Every mill uses this number unless it connects its own under Administration → Integrations.</p>
        </div>
    </x-filament::section>
    <x-filament::section heading="Firebase (notifications on party phones and browsers)">
        <div class="space-y-1 text-sm">
            <x-filament::badge :color="$s['firebase'] ? 'success' : 'gray'">{{ $label($s['firebase']) }}</x-filament::badge>
            @if ($s['firebase_project'])
                <p>Project {{ $s['firebase_project'] }} · browser notifications {{ $s['firebase_web'] ? 'ready' : 'need the web app settings and the Web Push key' }}</p>
            @endif
            <p class="text-gray-500">The service account key is kept encrypted in the server's private storage and never shown again.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
