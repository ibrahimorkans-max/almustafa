@php
    /** @var \App\Models\Hr\PayrollItem $item */
    $r = $item->result;
    $rs = fn ($p) => \App\Filament\Support\Units::paiseToRupees((int) $p);
    $sections = ['earnings' => 'Earnings', 'deductions' => 'Deductions', 'employer' => 'Employer contributions (not deducted)'];
@endphp
<div class="space-y-4 text-sm">
    @if (($r['warnings'] ?? []) !== [])
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 dark:border-warning-700 dark:bg-warning-950">
            <p class="mb-1 font-semibold text-warning-700 dark:text-warning-400">Warnings</p>
            <ul class="list-disc space-y-1 ps-5">
                @foreach ($r['warnings'] as $w)
                    <li>{{ $w }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @foreach ($sections as $key => $label)
        @if (($r[$key] ?? []) !== [])
            <div>
                <p class="mb-1 font-semibold">{{ $label }}</p>
                <table class="w-full">
                    @foreach ($r[$key] as $line)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1 pe-2">{{ $line['name'] }}</td>
                            <td class="py-1 pe-2 text-xs text-gray-500">{{ $line['basis'] }}</td>
                            <td class="py-1 text-end tabular-nums">{{ $rs($line['amount_paise']) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    @endforeach
    <table class="w-full font-semibold">
        <tr><td>Gross</td><td class="text-end tabular-nums">{{ $rs($item->gross_paise) }}</td></tr>
        <tr><td>Deductions</td><td class="text-end tabular-nums">{{ $rs($item->deductions_paise) }}</td></tr>
        <tr class="text-base"><td>Net pay</td><td class="text-end tabular-nums">{{ $rs($item->net_paise) }}</td></tr>
    </table>
    @if (($r['info'] ?? []) !== [])
        <details>
            <summary class="cursor-pointer text-xs text-gray-500">Working (days, minutes, rates)</summary>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                @foreach ($r['info'] as $k => $v)
                    <dt class="text-gray-500">{{ str_replace('_', ' ', $k) }}</dt><dd class="tabular-nums">{{ is_scalar($v) ? $v : json_encode($v) }}</dd>
                @endforeach
            </dl>
        </details>
    @endif
</div>
