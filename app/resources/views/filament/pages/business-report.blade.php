<x-filament-panels::page>
    @if (! $this->millChosen())
        <x-filament::section>
            <p class="text-sm">The report is for one mill. <a class="text-primary-600 underline" href="{{ $this->switchUrl() }}">Choose the working entity first</a>.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-gray-500">Period</span>
                    <select wire:model.live="period" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        @foreach (\App\Filament\Pages\BusinessReportPage::PERIODS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($period === 'custom')
                    <label class="text-sm"><span class="mb-1 block text-xs font-medium text-gray-500">From</span>
                        <input type="date" wire:model.live="from" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label>
                    <label class="text-sm"><span class="mb-1 block text-xs font-medium text-gray-500">To</span>
                        <input type="date" wire:model.live="to" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label>
                @endif
                @if (\App\Filament\Support\ExportGuard::allowed())
                <x-filament::button wire:click="downloadPdf" icon="heroicon-o-document-arrow-down">Download PDF</x-filament::button>
                <x-filament::button wire:click="downloadExcel" color="gray" icon="heroicon-o-table-cells">Download Excel</x-filament::button>
                @endif
                <x-filament::button tag="a" :href="\App\Filament\Pages\AllReportsPage::getUrl()" color="gray" icon="heroicon-o-printer">All reports</x-filament::button>
            </div>
            <p class="mt-2 text-xs text-gray-500">Everything for the period in one file: profit and loss, income, expenses, purchases, payments, cash flow, money owed, stock, vehicles in and out, weight checks, production, payroll and claims. The audit log is not included.</p>
        </x-filament::section>

        @php($report = $this->report())
        @if ($report === null)
            <x-filament::section><p class="text-sm text-danger-600">The end date is before the start date.</p></x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Key figures · {{ \Carbon\Carbon::parse($report['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($report['to'])->format('d M Y') }}</x-slot>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($report['headline'] as [$label, $value])
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <div class="text-xs text-gray-500">{{ $label }}</div>
                            <div class="mt-1 text-lg font-semibold {{ str_contains($label, 'LOSS') ? 'text-danger-600' : (str_contains($label, 'PROFIT') ? 'text-success-600' : '') }}">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500">{{ count($report['sections']) }} parts in the download: {{ collect($report['sections'])->map(fn ($s) => $s['title'].' ('.count($s['rows']).')')->implode(' · ') }}</p>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
