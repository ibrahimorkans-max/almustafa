<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Lockdown</x-slot>
        <x-slot name="description">While locked, only Super Admins can sign in (with 2FA). Gate agents show the lock and keep their readings queued; nothing is lost.</x-slot>
        @php($locks = $this->locks())
        @if ($locks === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">No lockdown is active.</p>
        @else
            <ul class="space-y-2">
                @foreach ($locks as $lock)
                    <li class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm dark:border-danger-700 dark:bg-danger-950">
                        <strong>{{ $lock->where }}</strong> locked by {{ $lock->by ?? 'unknown' }}
                        since {{ \Carbon\CarbonImmutable::parse($lock->locked_at, 'UTC')->timezone('Asia/Kolkata')->format('d M Y H:i') }} IST.
                        <div class="mt-1 text-gray-700 dark:text-gray-300">Reason: {{ $lock->reason }}</div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Maintenance</x-slot>
        <x-slot name="description">Scheduled or running windows. People see the message and the expected end time; health checks and document verification stay available.</x-slot>
        @php($windows = $this->maintenanceWindows())
        @if ($windows === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">No maintenance is scheduled.</p>
        @else
            <ul class="space-y-2">
                @foreach ($windows as $w)
                    <li class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-700 dark:bg-warning-950">
                        <strong>{{ $w->message }}</strong>
                        <div class="mt-1 text-gray-700 dark:text-gray-300">
                            {{ \Carbon\CarbonImmutable::parse($w->starts_at, 'UTC')->timezone('Asia/Kolkata')->format('d M H:i') }}
                            – {{ \Carbon\CarbonImmutable::parse($w->ends_at, 'UTC')->timezone('Asia/Kolkata')->format('d M H:i') }} IST
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
