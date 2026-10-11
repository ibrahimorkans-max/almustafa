@php
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500';
    $td = 'px-3 py-2 text-sm align-top';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Rates change with each Finance Act and the EOBI, PESSI and minimum-wage notifications. A change applies from its date; months
            already paid keep the value they were paid with. Values marked <strong>default</strong> are examples built into the system and
            must be confirmed by the owner or tax adviser before payroll is finalized.
        </p>
    </x-filament::section>
    <x-filament::section>
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">Setting</th><th class="{{ $th }}">In force today</th><th class="{{ $th }}">Source</th><th class="{{ $th }}"></th></tr></thead>
            <tbody>
                @foreach ($this->rows() as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="{{ $td }}">{{ $row['label'] }}</td>
                        <td class="{{ $td }} font-mono">{{ $row['value'] }}</td>
                        <td class="{{ $td }}">
                            @if ($row['default'])
                                <x-filament::badge color="warning">default — confirm</x-filament::badge>
                            @else
                                from {{ $row['from'] }} · {{ $row['note'] }}
                            @endif
                        </td>
                        <td class="{{ $td }} text-right">{{ ($this->changeAction)(['key' => $row['key']]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
