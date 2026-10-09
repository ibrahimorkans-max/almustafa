<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            Review regularly: suspend or revoke accounts nobody needs, finish pending setups, and check unusual permissions.
            "Dormant" means no sign-in for {{ $this->dormantDays() }} days.
        </x-slot>
        <div class="mb-4 flex gap-2">
            <x-filament::button size="sm" :color="$show === 'attention' ? 'primary' : 'gray'" wire:click="$set('show', 'attention')">Needs attention</x-filament::button>
            <x-filament::button size="sm" :color="$show === 'all' ? 'primary' : 'gray'" wire:click="$set('show', 'all')">All accounts</x-filament::button>
        </div>
        @php($rows = $this->rows())
        @if ($rows === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">No account needs attention.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="p-2">User</th><th class="p-2">Role</th><th class="p-2">Status</th><th class="p-2">2FA</th>
                            <th class="p-2">Last sign-in (IST)</th><th class="p-2">Attention</th><th class="p-2 text-right">Permissions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                                <td class="p-2"><div class="font-medium">{{ $r['username'] }}</div><div class="text-gray-500">{{ $r['person'] }} · {{ $r['entity'] }}</div></td>
                                <td class="p-2">{{ $r['role'] }}</td>
                                <td class="p-2">{{ $r['status'] }}</td>
                                <td class="p-2">{{ $r['two_factor'] }}</td>
                                <td class="p-2">{{ $r['last_login'] }}</td>
                                <td class="p-2 text-warning-700 dark:text-warning-400">{{ $r['flags'] ?: '—' }}</td>
                                <td class="p-2 text-right" title="{{ $r['permissions'] }}">{{ $r['permission_count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
