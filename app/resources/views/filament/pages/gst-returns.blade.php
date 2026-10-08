@php
    $d = $this->getData();
    $r1 = $d['r1'];
    $r3b = $d['r3b'];
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
            @unless ($r1['locked'])
                <x-filament::badge color="warning">The books for this month are not locked yet — figures can still change.</x-filament::badge>
            @endunless
        </div>
    </x-filament::section>

    <x-filament::section heading="GSTR-3B (outward supplies)">
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">3.1 (a) Outward taxable</th><th class="{{ $th }} text-right">Taxable</th><th class="{{ $th }} text-right">IGST</th><th class="{{ $th }} text-right">CGST</th><th class="{{ $th }} text-right">SGST</th></tr></thead>
            <tbody><tr><td class="{{ $td }}">Net of credit notes</td>
                <td class="{{ $td }} text-right">{{ $m($r3b['outward_taxable']['taxable']) }}</td><td class="{{ $td }} text-right">{{ $m($r3b['outward_taxable']['igst']) }}</td>
                <td class="{{ $td }} text-right">{{ $m($r3b['outward_taxable']['cgst']) }}</td><td class="{{ $td }} text-right">{{ $m($r3b['outward_taxable']['sgst']) }}</td></tr>
                <tr><td class="{{ $td }}">3.1 (c) Nil-rated</td><td class="{{ $td }} text-right">{{ $m($r3b['nil_rated']) }}</td><td colspan="3"></td></tr>
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="GSTR-1 · B2B invoices ({{ count($r1['b2b']) }})">
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">Buyer GSTIN</th><th class="{{ $th }}">Invoice</th><th class="{{ $th }}">Date</th><th class="{{ $th }}">POS</th><th class="{{ $th }}">Rate</th><th class="{{ $th }} text-right">Taxable</th><th class="{{ $th }} text-right">IGST</th><th class="{{ $th }} text-right">CGST</th><th class="{{ $th }} text-right">SGST</th><th class="{{ $th }} text-right">Value</th></tr></thead>
            <tbody>
                @forelse ($r1['b2b'] as $i)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="{{ $td }} font-mono">{{ $i['ctin'] }}</td><td class="{{ $td }} font-mono">{{ $i['number'] }}</td><td class="{{ $td }}">{{ $i['date'] }}</td><td class="{{ $td }}">{{ $i['pos'] }}</td><td class="{{ $td }}">{{ $pct($i['rate_bp']) }}</td>
                        <td class="{{ $td }} text-right">{{ $m($i['taxable']) }}</td><td class="{{ $td }} text-right">{{ $m($i['igst']) }}</td><td class="{{ $td }} text-right">{{ $m($i['cgst']) }}</td><td class="{{ $td }} text-right">{{ $m($i['sgst']) }}</td><td class="{{ $td }} text-right">{{ $m($i['value']) }}</td>
                    </tr>
                @empty
                    <tr><td class="{{ $td }} text-gray-500" colspan="10">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="GSTR-1 · B2C (small) and credit notes">
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">Table</th><th class="{{ $th }}">Supply / note</th><th class="{{ $th }}">POS</th><th class="{{ $th }}">Rate</th><th class="{{ $th }} text-right">Taxable</th><th class="{{ $th }} text-right">IGST</th><th class="{{ $th }} text-right">CGST</th><th class="{{ $th }} text-right">SGST</th></tr></thead>
            <tbody>
                @foreach ($r1['b2cs'] as $i)
                    <tr class="border-t border-gray-100 dark:border-white/5"><td class="{{ $td }}">B2CS</td><td class="{{ $td }}">{{ $i['supply'] }}</td><td class="{{ $td }}">{{ $i['pos'] }}</td><td class="{{ $td }}">{{ $pct($i['rate_bp']) }}</td>
                        <td class="{{ $td }} text-right">{{ $m($i['taxable']) }}</td><td class="{{ $td }} text-right">{{ $m($i['igst']) }}</td><td class="{{ $td }} text-right">{{ $m($i['cgst']) }}</td><td class="{{ $td }} text-right">{{ $m($i['sgst']) }}</td></tr>
                @endforeach
                @foreach ($r1['cdnr'] as $i)
                    <tr class="border-t border-gray-100 dark:border-white/5"><td class="{{ $td }}">CDNR</td><td class="{{ $td }} font-mono">{{ $i['number'] }} (against {{ $i['original_invoice'] }})</td><td class="{{ $td }}">{{ $i['pos'] }}</td><td class="{{ $td }}">{{ $pct($i['rate_bp']) }}</td>
                        <td class="{{ $td }} text-right">{{ $m($i['taxable']) }}</td><td class="{{ $td }} text-right">{{ $m($i['igst']) }}</td><td class="{{ $td }} text-right">{{ $m($i['cgst']) }}</td><td class="{{ $td }} text-right">{{ $m($i['sgst']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="GSTR-1 · HSN summary">
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">HSN</th><th class="{{ $th }}">Description</th><th class="{{ $th }} text-right">Quantity (kg)</th><th class="{{ $th }}">Rate</th><th class="{{ $th }} text-right">Taxable</th><th class="{{ $th }} text-right">IGST</th><th class="{{ $th }} text-right">CGST</th><th class="{{ $th }} text-right">SGST</th></tr></thead>
            <tbody>
                @foreach ($r1['hsn'] as $h)
                    <tr class="border-t border-gray-100 dark:border-white/5"><td class="{{ $td }} font-mono">{{ $h['hsn'] }}</td><td class="{{ $td }}">{{ $h['description'] }}</td><td class="{{ $td }} text-right">{{ $h['quantity_kg'] }}</td><td class="{{ $td }}">{{ $pct($h['rate_bp']) }}</td>
                        <td class="{{ $td }} text-right">{{ $m($h['taxable']) }}</td><td class="{{ $td }} text-right">{{ $m($h['igst']) }}</td><td class="{{ $td }} text-right">{{ $m($h['cgst']) }}</td><td class="{{ $td }} text-right">{{ $m($h['sgst']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
