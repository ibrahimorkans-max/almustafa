{{-- Complete details of one vehicle (slide-over on the vehicle register). Param: $d = VehicleRegister::details(). --}}
@php
    $row = $d['row'];
    $inbound = $row['direction'] === 'inbound';
    $price = $row['price'];
@endphp
<div class="space-y-5 text-sm">
    @if ($d['flags'] !== [])
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
            {{ implode(' · ', $d['flags']) }}
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($d['sections'] as $section)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10 {{ $loop->last ? 'md:col-span-2' : '' }}">
                <h3 class="mb-2 font-semibold">{{ $section['title'] }}</h3>
                <table class="w-full">
                    @foreach (array_chunk($section['fields'], $loop->last ? 2 : 1) as $pair)
                        <tr>
                            @foreach ($pair as [$label, $value])
                                <td class="py-0.5 pr-3 align-top text-gray-500" style="white-space: nowrap;">{{ $label }}</td><td class="py-0.5 pr-4 align-top font-medium">{{ $value }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <h3 class="mb-2 font-semibold">Weighments (from the weighbridge)</h3>
        @if ($d['weighments'] === [])
            <p class="text-gray-500">Not weighed yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="text-xs text-gray-500"><tr><th class="py-1 pr-3">Weighment</th><th class="pr-3">Time</th><th class="pr-3">Scale · source</th><th class="pr-3">By</th><th class="text-right">Weight</th></tr></thead>
                    <tbody>
                        @foreach ($d['weighments'] as $w)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1 pr-3">{{ $w['kind'] }}@if ($w['note'] !== '')<div class="text-xs text-gray-500">{{ $w['note'] }}</div>@endif</td>
                                <td class="pr-3">{{ $w['when'] }}</td><td class="pr-3">{{ $w['scale'] }} · {{ $w['source'] }}</td><td class="pr-3">{{ $w['by'] }}</td>
                                <td class="text-right font-mono">{{ $w['weight'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($d['lab'] !== [])
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <h3 class="mb-2 font-semibold">Lab test</h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
                @foreach ($d['lab'] as [$label, $value])
                    <div><dt class="text-xs text-gray-500">{{ $label }}</dt><dd class="font-medium">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <h3 class="mb-2 font-semibold">How the weight was worked out</h3>
            <ol class="list-decimal space-y-1 pl-5">
                @foreach ($d['weightSteps'] as $step)<li>{{ $step }}</li>@endforeach
            </ol>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <h3 class="mb-2 font-semibold">How the {{ $inbound ? 'payable price' : 'invoice value' }} was worked out <span class="text-xs font-normal text-gray-500">({{ $price['label'] }})</span></h3>
            <ol class="list-decimal space-y-1 pl-5">
                @foreach ($d['priceSteps'] as $step)<li>{{ $step }}</li>@endforeach
            </ol>
            <p class="mt-2 text-base font-semibold text-primary-600">{{ $inbound ? 'Payable to supplier' : 'Receivable from buyer' }}: {{ \App\Domain\Gate\VehicleRegister::rs($price['net_paise']) }}{{ $price['state'] === 'estimate' ? ' (estimate)' : '' }}</p>
        </div>
    </div>

    @if ($d['timeline'] !== [])
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <h3 class="mb-2 font-semibold">Timeline</h3>
            <ul class="space-y-1">
                @foreach ($d['timeline'] as [$when, $what, $by])
                    <li class="flex gap-3"><span class="w-24 shrink-0 font-mono text-xs text-gray-500">{{ $when }}</span><span class="flex-1">{{ $what }}</span><span class="text-xs text-gray-500">{{ $by }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
