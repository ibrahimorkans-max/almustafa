<x-filament-panels::page>
    {{ $this->table }}

    <x-filament::section heading="Reconciliation: estimated against what providers billed" description="Estimated: the usage records × your price list. Billed: what you recorded from each provider's invoice." collapsible>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500"><th class="py-1 pr-3">Month</th><th class="pr-3">Provider</th><th class="pr-3 text-right">Estimated</th><th class="pr-3 text-right">Billed</th><th class="text-right">Difference</th></tr></thead>
            <tbody>
                @forelse (\App\Domain\Usage\UsageReport::reconciliation() as $r)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1 pr-3">{{ $r['month'] }}</td><td class="pr-3">{{ $r['provider'] }}</td>
                        <td class="pr-3 text-right">{{ $r['estimated'] }}</td><td class="pr-3 text-right">{{ $r['billed'] }}</td>
                        <td @class(['text-right', 'text-danger-600' => (float) $r['difference'] > 0, 'text-success-600' => (float) $r['difference'] < 0])>{{ $r['difference'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Company statements" collapsible>
        @php($names = $this->companyNames())
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500"><th class="py-1 pr-3">Month</th><th class="pr-3">Company</th><th class="pr-3">Lines</th><th class="pr-3 text-right">Subtotal</th><th class="pr-3 text-right">Tax</th><th class="text-right">Total</th></tr></thead>
            <tbody>
                @forelse ($this->statements() as $s)
                    <tr class="border-t border-gray-100 align-top dark:border-white/5">
                        <td class="py-1 pr-3">{{ $s->period->format('M Y') }}</td><td class="pr-3">{{ $names[$s->customer_id] ?? '—' }}</td>
                        <td class="pr-3 text-xs">@foreach ($s->lines as $l){{ \App\Models\Usage\ServiceUsage::CHANNELS[$l['channel']] ?? $l['channel'] }} · {{ $l['provider'] }}: {{ $l['units'] ?? 'package' }} → {{ $l['charge'] }}<br>@endforeach</td>
                        <td class="pr-3 text-right">{{ $s->subtotal }}</td><td class="pr-3 text-right">{{ $s->tax }}</td><td class="text-right font-semibold">{{ $s->currency }} {{ $s->total }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">No statements issued yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
