<x-filament-panels::page>
    @if (! $this->millChosen())
        <x-filament::section>
            <p class="text-sm">Sign-in rules are set per mill. <a class="text-primary-600 underline" href="{{ $this->switchUrl() }}">Choose the working entity first</a>.</p>
        </x-filament::section>
    @else
    <form wire:submit="save" class="space-y-6">
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>
        <x-filament::button type="submit">Save</x-filament::button>
    </form>

    <x-filament::section>
        <x-slot name="heading">How approval works</x-slot>
        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>Signing in from a new device is stopped with a message, and a request appears under <a class="text-primary-600 underline" href="{{ $this->approvalsUrl() }}">Approvals</a> for the Super Admin. Nobody approves their own device.</li>
            <li>Once approved, the person signs in again from that device; it stays approved until it is revoked or reported lost under Devices.</li>
            <li>Devices waiting for approval now: <strong>{{ $this->pendingDevices() }}</strong>.</li>
            <li>To limit an account to the mill's networks, add its IP addresses under Users → the account → IP allow-list.</li>
            <li>Every sign-in from a new device or a new city is reported to the person and to the Super Admin.</li>
        </ul>
    </x-filament::section>
    @endif
</x-filament-panels::page>
