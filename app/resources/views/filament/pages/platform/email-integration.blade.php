<x-filament-panels::page>
    @php($health = $this->getHealth())
    <x-filament::section heading="Providers">
        <p class="mb-3 text-sm text-gray-500">Mailboxes are only read, never changed. Client ids and secrets live in the server's .env file, never in the database or on a screen. Gmail's read-only access needs Google's app verification before customers outside the test list can connect.</p>
        <ul class="space-y-2 text-sm">
            @foreach ($this->getProviders() as $p)
                <li class="flex flex-wrap items-start gap-2">
                    <x-filament::badge :color="$p['ready'] ? 'success' : 'gray'">{{ $p['ready'] ? 'Ready' : 'Not set up' }}</x-filament::badge>
                    <span class="font-medium">{{ $p['label'] }}</span>
                    <span class="w-full text-xs text-gray-500">{{ $p['how'] }}</span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament::section heading="Sync policy and health">
        <p class="text-sm">Each mailbox is read on its own interval (at least every {{ $health['minimum'] }} minutes; BANK_EMAIL_MIN_SYNC_MINUTES), by the scheduled job <code>bank-email:sync</code>, away from people's screens. A failed reading is tried again on the next interval.</p>
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            @forelse ($health['connections'] as $status => $n)
                <x-filament::badge :color="$status === 'connected' ? 'success' : ($status === 'error' ? 'danger' : 'gray')">{{ \App\Models\Banking\EmailConnection::STATUS_LABELS[$status] ?? $status }}: {{ $n }}</x-filament::badge>
            @empty
                <span class="text-gray-500">No mailbox connected yet.</span>
            @endforelse
        </div>
        @if ($health['errors'] !== [])
            <h3 class="mt-4 text-sm font-semibold">Recent errors</h3>
            <table class="mt-1 w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="pr-3">When</th><th class="pr-3">Mill</th><th class="pr-3">Mailbox</th><th>Error</th></tr></thead>
                <tbody>
                    @foreach ($health['errors'] as $e)
                        <tr><td class="pr-3">{{ $e['when'] }}</td><td class="pr-3">{{ $e['mill'] }}</td><td class="pr-3">{{ $e['email'] }}</td><td class="text-danger-600">{{ $e['error'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
