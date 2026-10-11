<x-filament-panels::page>
    <x-filament::section>
        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="text-sm">Month
                <input type="month" wire:model.live="month" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
            </label>
            <label class="text-sm">Report
                <select wire:model.live="report" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                    @foreach (\App\Domain\Hr\Payroll\StatutoryReports::REPORTS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <p class="text-xs text-gray-500">From finalized payroll only. Check the institution's current upload format before filing; full CNICs only for users allowed to see them.</p>
        </div>
        @php($data = $this->getData())
        @if (is_string($data))
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $data }}</p>
        @elseif ($data['rows'] === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">Nobody to report in {{ $month }}.</p>
        @else
            <p class="mb-2 font-semibold">{{ $data['title'] }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                            @foreach ($data['header'] as $h)
                                <th class="p-1">{{ $h }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach ($row as $v)
                                    <td @class(['p-1 whitespace-nowrap', 'text-end tabular-nums' => is_int($v) || is_float($v)])>{{ is_float($v) ? number_format($v, 2) : $v }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
