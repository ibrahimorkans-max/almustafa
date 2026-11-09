<x-filament-panels::page>
    @php($s = $this->getState())
    @if (! $s['mill_chosen'])
        <x-filament::section>Choose a mill first (top right: Switch entity / account): each mill has its own sender identity.</x-filament::section>
    @else
        <p class="text-sm text-gray-500">These services are connected and managed by the SaaS owner for every mill. Nothing is connected here, and no keys or passwords are shown.</p>
        <x-filament::section heading="Senders: who your messages come from">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500"><th class="py-1 pr-3">Service</th><th class="pr-3">Status</th><th class="pr-3">Your sender identity</th></tr></thead>
                <tbody>
                    @foreach (['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-mail'] as $ch => $label)
                        @php($row = $s[$ch])
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-1.5 pr-3 font-medium">{{ $label }}</td>
                            <td class="pr-3">
                                @if ($row && $row->status === 'approved')
                                    <x-filament::badge color="success">Connected</x-filament::badge>
                                @elseif ($row)
                                    <x-filament::badge color="warning">{{ \App\Domain\Messaging\SenderIdentities::STATUSES[$row->status] ?? $row->status }}</x-filament::badge>
                                @elseif ($ch === 'whatsapp' && $s['whatsapp_connected'])
                                    <x-filament::badge color="success">Connected</x-filament::badge>
                                @elseif ($ch === 'email')
                                    <x-filament::badge color="info">Sent under your mill's name</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">Not assigned</x-filament::badge>
                                @endif
                            </td>
                            <td class="pr-3">
                                @if ($row)
                                    {{ $row->display_name }} <span class="text-xs text-gray-500">({{ $ch === 'email' ? $row->sender : \App\Domain\Usage\UsageMeter::mask((string) $row->sender) }})</span>
                                    @if ($row->platform_sender)<div class="text-xs text-warning-600">Goes out under the platform's name, as the SaaS owner set it.</div>@endif
                                @elseif ($ch === 'whatsapp')
                                    {{ $s['whatsapp_name'] }} <span class="text-xs text-gray-500">(the platform's WhatsApp number; your name is in every message)</span>
                                @elseif ($ch === 'email')
                                    {{ $s['mail_from']->name }} <span class="text-xs text-gray-500">({{ $s['mail_from']->address }})</span>
                                @else
                                    <span class="text-gray-500">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1.5 pr-3 font-medium">AI services</td>
                        <td class="pr-3"><x-filament::badge :color="$s['ai'] ? 'success' : 'gray'">{{ $s['ai'] ? 'Available' : 'Unavailable' }}</x-filament::badge></td>
                        <td class="pr-3 text-gray-500">The assistant and the roznamcha reader; each use is counted against your mill.</td>
                    </tr>
                </tbody>
            </table>
            @if ($s['whatsapp_problem'])
                <p class="mt-2 text-sm text-warning-600">{{ $s['whatsapp_problem'] }} Until then WhatsApp messages wait under Masters → Party messages to be sent by hand.</p>
            @endif
            <p class="mt-2 text-xs text-gray-500">Last service activity: {{ $s['last'] ? $s['last']->occurred_at->timezone('Asia/Karachi')->format('d M Y H:i').' — '.$s['last']->provider.', '.$s['last']->module : 'none yet' }}. The details are under Administration → Service usage.</p>
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
