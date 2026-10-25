{{-- Under the code: switch to a recovery code (lost phone) and back, or start again with another account. --}}
<div class="flex flex-col items-center gap-1 text-sm">
    <button type="button" wire:click="toggleRecoveryCode" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
        {{ $this->useRecoveryCode ? 'Use the 6-digit code instead' : 'Lost your phone? Use a recovery code' }}
    </button>
    <button type="button" wire:click="cancelCode" class="text-gray-500 hover:underline dark:text-gray-400">Back to sign-in</button>
</div>
