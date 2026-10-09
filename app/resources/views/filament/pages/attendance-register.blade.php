<x-filament-panels::page>
    <x-filament::section>
        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="text-sm">Month
                <input type="month" wire:model.live="month" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
            </label>
            <label class="text-sm">Department
                <select wire:model.live="department" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="">All</option>
                    @foreach ($this->departments() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <p class="text-xs text-gray-500">P present · ½P half day · A absent · L / ½L leave · HO holiday · WO weekly off · MO missing out-punch (not counted until fixed). Only punches accepted by the rules or on review count.</p>
        </div>
        @php($r = $this->register())
        @if ($r['rows'] === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">No employees in this month.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-center text-xs">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="sticky left-0 bg-white p-1 text-left dark:bg-gray-900">Employee</th>
                            @foreach ($r['dates'] as $d)
                                <th class="p-1 font-normal">{{ (int) substr($d, 8, 2) }}</th>
                            @endforeach
                            <th class="p-1">Present</th><th class="p-1">Paid leave</th><th class="p-1">LWP</th><th class="p-1">Absent</th>
                            <th class="p-1">Late (min)</th><th class="p-1">OT (min)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($r['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="sticky left-0 whitespace-nowrap bg-white p-1 text-left dark:bg-gray-900">{{ $row['code'] }} · {{ $row['name'] }}</td>
                                @foreach ($row['days'] as $code)
                                    <td @class(['p-1 whitespace-nowrap', 'text-danger-600' => $code === 'A' || $code === 'MO', 'text-gray-400' => in_array($code, ['HO', 'WO', '—'], true)])>{{ $code }}</td>
                                @endforeach
                                <td class="p-1">{{ $row['totals']['present'] }}</td>
                                <td class="p-1">{{ $row['totals']['paid_leave'] }}</td>
                                <td class="p-1">{{ $row['totals']['unpaid_leave'] }}</td>
                                <td class="p-1">{{ $row['totals']['absent'] }}</td>
                                <td class="p-1">{{ $row['totals']['late_minutes'] }}</td>
                                <td class="p-1">{{ $row['totals']['overtime_minutes'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
