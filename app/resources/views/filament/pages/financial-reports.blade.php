@php
    use App\Domain\Reports\FinancialTables;
    $t = $this->getTable();
    $cell = fn ($c) => is_int($c) ? FinancialTables::money($c) : ($c ?? '');
    $th = 'px-3 py-2 text-xs font-semibold uppercase text-gray-500';
@endphp
<x-filament-panels::page>
    <x-filament::section>{{ $this->form }}</x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ $t['title'] }}</x-slot>
        <x-slot name="description">{{ $t['subtitle'] }}</x-slot>

        <table class="w-full">
            @if ($t['columns'][0] !== '' || count($t['columns']) > 2)
                <thead>
                    <tr>
                        @foreach ($t['columns'] as $i => $col)
                            <th class="{{ $th }} {{ $i === 0 ? 'text-left' : 'text-right' }}">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
            @endif
            <tbody>
                @forelse ($t['rows'] as $row)
                    @php
                        $style = match ($row['style']) {
                            'heading' => 'pt-4 font-semibold text-gray-700 dark:text-gray-200',
                            'total' => 'border-t border-gray-200 font-semibold dark:border-white/10',
                            'grand' => 'border-y-2 border-gray-300 bg-gray-50 text-base font-bold dark:border-white/20 dark:bg-white/5',
                            default => 'border-t border-gray-100 dark:border-white/5',
                        };
                    @endphp
                    <tr class="{{ $style }}">
                        <td class="px-3 py-1.5 text-sm {{ $row['style'] === 'line' ? 'pl-6' : '' }}">
                            @if (isset($row['ledger']))
                                <a href="{{ $this->ledgerUrl($row['ledger']) }}" class="text-primary-600 hover:underline dark:text-primary-400" title="Open the ledger statement">{{ $row['label'] }}</a>
                            @else
                                {{ $row['label'] }}
                            @endif
                        </td>
                        @foreach ($row['cells'] as $c)
                            <td class="px-3 py-1.5 text-right text-sm tabular-nums {{ is_int($c) && $c < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $cell($c) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="px-3 py-4 text-sm text-gray-500">Nothing in this period.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($t['notes'] !== [])
            <ul class="mt-4 list-disc space-y-1 pl-5 text-xs text-gray-500">
                @foreach ($t['notes'] as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
