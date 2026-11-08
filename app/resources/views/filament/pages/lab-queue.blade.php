<x-filament-panels::page>
    <div class="rounded-lg bg-white p-3 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
        @if ($this->weighFirst())
            Vehicles are <strong>weighed as soon as they arrive</strong>, without waiting for the lab. Each paddy vehicle stays here until its lab
            test is added and the lab accepts or rejects it; open a vehicle to add its lab test. It is unloaded (and weighed empty) only after the
            lab accepts it.
        @else
            The <strong>lab accepts each lot before it is weighed</strong>. Open a vehicle to add its lab test.
        @endif
    </div>
    {{ $this->table }}
</x-filament-panels::page>
