@php
    $d = $this->getData();
    $s = $d['sales'];
    $m = fn (int $p) => \App\Filament\Support\Units::paiseToRupees($p);
    $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500';
    $td = 'px-3 py-1.5 text-sm';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-end gap-3">
            <label>
                <span class="text-sm font-medium">Month</span>
                <x-filament::input.wrapper><x-filament::input type="month" wire:model.live="period" /></x-filament::input.wrapper>
            </label>
            @unless ($s['locked'])
                <x-filament::badge color="warning">The books for this month are not locked yet — figures can still change.</x-filament::badge>
            @endunless
        </div>
        <p class="mt-3 text-sm text-gray-500">Rates and the IRIS layouts change with each Finance Act and FBR circular: the tax adviser checks these registers before filing.</p>
    </x-filament::section>

    <x-filament::section heading="Sales tax return — summary">
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">Sales excluding tax</th><th class="{{ $th }} text-right">Taxable</th><th class="{{ $th }} text-right">Exempt / zero-rated</th><th class="{{ $th }} text-right">Sales tax</th><th class="{{ $th }} text-right">Further tax</th><th class="{{ $th }} text-right">Total value</th></tr></thead>
            <tbody><tr><td class="{{ $td }}">Net of credit notes</td>
                <td class="{{ $td }} text-right">{{ $m($s['summary']['taxable']) }}</td><td class="{{ $td }} text-right">{{ $m($s['summary']['exempt']) }}</td>
                <td class="{{ $td }} text-right">{{ $m($s['summary']['sales_tax']) }}</td><td class="{{ $td }} text-right">{{ $m($s['summary']['further_tax']) }}</td>
                <td class="{{ $td }} text-right font-semibold">{{ $m($s['summary']['value']) }}</td></tr>
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Annex-C · sales and credit notes ({{ count($s['sales']) }})">
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">Buyer</th><th class="{{ $th }}">NTN / CNIC</th><th class="{{ $th }}">Type</th><th class="{{ $th }}">Document</th><th class="{{ $th }}">Date</th><th class="{{ $th }}">HS code</th><th class="{{ $th }}">Rate</th><th class="{{ $th }} text-right">Value excl. tax</th><th class="{{ $th }} text-right">Sales tax</th><th class="{{ $th }} text-right">Further tax</th></tr></thead>
            <tbody>
                @forelse ($s['sales'] as $i)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="{{ $td }}">{{ $i['buyer_name'] }} <span class="text-xs text-gray-500">{{ $i['buyer_type'] }}</span></td>
                        <td class="{{ $td }} font-mono">{{ \App\Support\Values\PakistanIds::formatNtn($i['buyer_ntn']) ?? '—' }}</td>
                        <td class="{{ $td }}">{{ $i['type'] === 'CN' ? 'Credit note' : 'Sale' }}</td>
                        <td class="{{ $td }} font-mono">{{ $i['number'] }}@if ($i['against']) <span class="text-xs text-gray-500">(against {{ $i['against'] }})</span>@endif</td>
                        <td class="{{ $td }}">{{ $i['date'] }}</td><td class="{{ $td }} font-mono">{{ $i['pct'] ?? '—' }}</td>
                        <td class="{{ $td }}">{{ $i['exempt'] ? 'Exempt' : $pct($i['rate_bp']) }}</td>
                        <td class="{{ $td }} text-right">{{ $m($i['taxable']) }}</td><td class="{{ $td }} text-right">{{ $m($i['sales_tax']) }}</td><td class="{{ $td }} text-right">{{ $m($i['further_tax']) }}</td>
                    </tr>
                @empty
                    <tr><td class="{{ $td }} text-gray-500" colspan="10">No posted invoices this month.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Income tax withheld u/s 153(1)(a) ({{ count($d['wht']) }})">
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">Supplier</th><th class="{{ $th }}">NTN / CNIC</th><th class="{{ $th }}">Filer</th><th class="{{ $th }}">Slip</th><th class="{{ $th }}">Date</th><th class="{{ $th }} text-right">Value of goods</th><th class="{{ $th }} text-right">Taxable</th><th class="{{ $th }}">Rate</th><th class="{{ $th }} text-right">Withheld</th></tr></thead>
            <tbody>
                @forelse ($d['wht'] as $i)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="{{ $td }}">{{ $i['supplier'] }}</td>
                        <td class="{{ $td }} font-mono">{{ \App\Support\Values\PakistanIds::formatNtn($i['ntn'] ?? $i['cnic']) ?? '—' }}</td>
                        <td class="{{ $td }}">{{ $i['filer'] ? 'Yes' : 'No' }}</td><td class="{{ $td }} font-mono">{{ $i['bill'] }}</td><td class="{{ $td }}">{{ $i['date'] }}</td>
                        <td class="{{ $td }} text-right">{{ $m($i['gross']) }}</td><td class="{{ $td }} text-right">{{ $m($i['base']) }}</td><td class="{{ $td }}">{{ $pct($i['rate_bp']) }}</td>
                        <td class="{{ $td }} text-right font-semibold">{{ $m($i['tax']) }}</td>
                    </tr>
                @empty
                    <tr><td class="{{ $td }} text-gray-500" colspan="9">No tax withheld this month.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
