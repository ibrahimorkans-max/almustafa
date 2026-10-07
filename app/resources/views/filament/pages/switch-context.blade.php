<x-filament-panels::page>
    @if ($this->account()->isSuperAdmin())
        <x-filament::section>
            <x-slot name="heading">Working entity</x-slot>
            <x-slot name="description">Lists and new records use the working entity. Every change of working entity is audited.</x-slot>
            <div class="flex flex-wrap gap-2">
                <x-filament::button
                    :color="$this->workingEntityId() === null ? 'primary' : 'gray'"
                    wire:click="chooseEntity('all')">
                    All entities
                </x-filament::button>
                @foreach ($this->entities() as $entity)
                    <x-filament::button
                        :color="$this->workingEntityId() === $entity->id ? 'primary' : 'gray'"
                        wire:click="chooseEntity('{{ $entity->id }}')">
                        {{ $entity->code }} — {{ $entity->name }}
                    </x-filament::button>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Your other login accounts</x-slot>
        @php($others = $this->otherAccounts())
        @if ($others->isEmpty())
            <p class="text-sm text-gray-500">
                No other accounts available. (Switching requires the "single login with entity switcher" mode
                and another active account for you in a different entity.)
            </p>
        @else
            <div class="mb-4 max-w-xs">
                <label class="text-sm">Authentication code (if the target account uses 2FA)</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="otp" autocomplete="one-time-code" />
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-2">
                @foreach ($others as $other)
                    <div class="flex items-center justify-between rounded border p-3 dark:border-gray-700">
                        <div>
                            <div class="font-medium">{{ $other->entity->name }}</div>
                            <div class="text-sm text-gray-500">{{ $other->username }} · {{ $other->role->name }}</div>
                        </div>
                        <x-filament::button wire:click="switchAccount('{{ $other->id }}')">Switch</x-filament::button>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
