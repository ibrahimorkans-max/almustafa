<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $rs = fn (int $p) => \App\Support\Values\Money::paise($p)->format();
        $due = array_sum(array_column($rows, 'due_paise'));
        $adv = array_sum(array_column($rows, 'advance_paise'));
        $paid = array_sum(array_column($rows, 'paid_paise'));
    @endphp

    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500" for="eb-month">{{ __('Month') }}</label>
            <input id="eb-month" type="month" wire:model.live="month" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500" for="eb-search">{{ __('Search employee') }}</label>
            <input id="eb-search" type="search" wire:model.live.debounce.400ms="search" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>
        @if (\App\Domain\Hr\SalaryVouchers::allowed())
            <x-filament::button tag="a" :href="\App\Filament\Resources\Hr\SalaryVoucherResource::getUrl('create')" icon="heroicon-o-banknotes">{{ __('New salary voucher') }}</x-filament::button>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-filament::section><p class="text-xs text-gray-500">{{ __('Salary paid this month') }}</p><p class="text-2xl font-bold text-success-600" dir="ltr">{{ $rs($paid) }}</p></x-filament::section>
        <x-filament::section><p class="text-xs text-gray-500">{{ __('Salary still due') }}</p><p class="text-2xl font-bold text-danger-600" dir="ltr">{{ $rs($due) }}</p></x-filament::section>
        <x-filament::section><p class="text-xs text-gray-500">{{ __('Advances and loans outstanding') }}</p><p class="text-2xl font-bold text-warning-600" dir="ltr">{{ $rs($adv) }}</p></x-filament::section>
    </div>

    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b text-xs text-gray-500 dark:border-white/10">
                    <th class="p-2 text-start">{{ __('Employee') }}</th><th class="p-2 text-end">{{ __('Salary') }}</th>
                    <th class="p-2 text-end">{{ __('Paid') }}</th><th class="p-2 text-end">{{ __('Still due') }}</th>
                    <th class="p-2 text-end">{{ __('Advance / loan balance') }}</th><th class="p-2 text-start">{{ __('Last paid') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr class="border-b dark:border-white/5" wire:key="eb-{{ $r['employee_id'] }}">
                        <td class="p-2 font-medium">{{ $r['code'] }} — {{ $r['name'] }}</td>
                        <td class="p-2 text-end" dir="ltr">{{ $r['basis'] === null ? __('not set') : $rs($r['salary_paise']).($r['basis'] === 'daily' ? ' / '.__('day') : '') }}</td>
                        <td class="p-2 text-end" dir="ltr">
                            @if ($r['paid'])<x-filament::badge color="success" class="inline-flex">{{ $rs($r['paid_paise']) }}</x-filament::badge>@else — @endif
                        </td>
                        <td class="p-2 text-end font-semibold text-danger-700" dir="ltr">{{ $r['due_paise'] > 0 ? $rs($r['due_paise']) : '—' }}</td>
                        <td class="p-2 text-end text-warning-700" dir="ltr">{{ $r['advance_paise'] > 0 ? $rs($r['advance_paise']) : '—' }}</td>
                        <td class="p-2">{{ $r['last_paid'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-4 text-center text-gray-500">{{ __('No employees.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500">{{ __('Paid = salary vouchers and finalized payroll runs for the month. Daily-wage employees show no amount due: it depends on the days worked.') }}</p>
    </x-filament::section>
</x-filament-panels::page>
