<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Add a passkey on this device</x-slot>
        <x-slot name="description">
            A passkey lets you sign in with your fingerprint, face or the device's PIN instead of the password and 6-digit code.
            The key stays on this device; nobody can copy it from the server, and it does not work on a fake sign-in page.
        </x-slot>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="flex-1 text-sm">
                <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Name it after the device</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="name" maxlength="100" placeholder="e.g. Office PC, My phone" />
                </x-filament::input.wrapper>
            </label>
            <x-filament::button icon="heroicon-o-finger-print" x-on:click="rmPasskeyRegister($wire, $wire.name)">
                Add passkey
            </x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Your passkeys</x-slot>
        @php($passkeys = $this->getPasskeys())
        @if ($passkeys->isEmpty())
            <p class="text-sm text-gray-500">None yet. You sign in with your password and code.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="px-3 py-2">Name</th><th class="px-3 py-2">Added</th><th class="px-3 py-2">Last used</th><th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($passkeys as $p)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2 font-medium">{{ $p->name }}</td>
                            <td class="px-3 py-2">{{ $p->created_at->timezone('Asia/Karachi')->format('d M Y') }}</td>
                            <td class="px-3 py-2">{{ $p->last_used_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? 'never' }}</td>
                            <td class="px-3 py-2 text-right">
                                <x-filament::button size="xs" color="danger" outlined wire:click="remove('{{ $p->id }}')"
                                    wire:confirm="Remove this passkey? That device will then need the password and code.">Remove</x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
