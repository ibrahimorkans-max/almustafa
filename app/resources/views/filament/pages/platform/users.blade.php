<x-filament-panels::page>
    @php($sel = 'block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5')
    <div class="grid gap-3 sm:grid-cols-3">
        <label class="text-sm"><span class="text-gray-500">Search</span><input type="search" wire:model.live.debounce.400ms="search" placeholder="Name or username" class="{{ $sel }}"></label>
        <label class="text-sm"><span class="text-gray-500">Company</span>
            <select wire:model.live="company" class="{{ $sel }}"><option value="">All companies</option>@foreach ($this->companies() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        <label class="text-sm"><span class="text-gray-500">Status</span>
            <select wire:model.live="status" class="{{ $sel }}"><option value="">Any</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="revoked">Revoked</option></select></label>
    </div>
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500"><th class="py-1 pr-3">User</th><th class="pr-3">Company · mill</th><th class="pr-3">Role</th><th class="pr-3">Status</th><th class="pr-3">Last sign-in</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->users() as $u)
                        <tr class="border-t border-gray-100 dark:border-white/5" wire:key="u-{{ $u['id'] }}">
                            <td class="py-1.5 pr-3"><div class="font-medium">{{ $u['name'] ?? $u['username'] }}</div><div class="text-xs text-gray-500">{{ $u['username'] }}{{ $u['platform'] ? ' · SaaS owner' : '' }}</div></td>
                            <td class="pr-3">{{ $u['company'] }}<div class="text-xs text-gray-500">{{ $u['mill'] }}</div></td>
                            <td class="pr-3">{{ $u['role'] ?? '—' }}</td>
                            <td class="pr-3"><x-filament::badge :color="$u['status'] === 'active' ? 'success' : ($u['status'] === 'revoked' ? 'danger' : 'warning')">{{ $u['status'] }}</x-filament::badge></td>
                            <td class="pr-3 whitespace-nowrap">{{ $u['last_login'] ?? 'never' }}</td>
                            <td class="whitespace-nowrap text-right">
                                @unless ($u['platform'])
                                    {{ ($this->roleAction)(['user' => $u['id']]) }} {{ ($this->accessAction)(['user' => $u['id']]) }} {{ ($this->passwordAction)(['user' => $u['id']]) }}
                                @endunless
                                {{ ($this->historyAction)(['user' => $u['id']]) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-2 text-gray-500">No users match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if (count($this->users()) >= $limit)
            <x-filament::button color="gray" size="sm" class="mt-3" wire:click="$set('limit', {{ $limit + 100 }})">Show more</x-filament::button>
        @endif
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
