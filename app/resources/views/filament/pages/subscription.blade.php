@php
    $s = $this->subscription();
    $plan = $s['plan'];
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $s['customer']->name }} · {{ $s['customer']->code }}</div>
                <div class="text-2xl font-bold">{{ $plan?->name ?? 'Full access (no plan)' }}</div>
                @if ($plan)
                    <div class="text-sm text-gray-500">{{ $plan->description }}</div>
                @endif
            </div>
            <div class="text-right">
                @if ($s['customer']->isLifetime())
                    <div class="text-xl font-semibold text-success-600 dark:text-success-400">Lifetime access</div>
                    <div class="text-sm text-gray-500">No payments due, ever</div>
                @elseif ($plan)
                    <div class="text-xl font-semibold">{{ \App\Filament\Support\Units::paiseToRupees($plan->price_paise_monthly) }} <span class="text-sm font-normal text-gray-500">/ month</span></div>
                @endif
                <x-filament::badge :color="match ($s['customer']->status) { 'active' => 'success', 'trial' => 'info', 'past_due' => 'warning', default => 'danger' }">
                    {{ \App\Filament\Resources\Platform\CustomerResource::STATUSES[$s['customer']->status] ?? $s['customer']->status }}{{ $s['customer']->status === 'trial' && $s['customer']->trial_ends_at ? ' until '.$s['customer']->trial_ends_at->format('d M Y') : '' }}
                </x-filament::badge>
                @if ($s['customer']->trialDaysLeft() !== null)
                    <div class="mt-1 text-sm text-gray-500">{{ $s['customer']->trialDaysLeft() }} {{ \Illuminate\Support\Str::plural('day', $s['customer']->trialDaysLeft()) }} of the free trial left</div>
                @endif
            </div>
        </div>
    </x-filament::section>

    <div class="grid gap-6 md:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Usage</x-slot>
            <div class="space-y-4">
                @foreach ($s['limits'] as $l)
                    @php($pct = $l['max'] ? min(100, (int) round($l['used'] * 100 / max(1, $l['max']))) : 0)
                    <div>
                        <div class="flex justify-between text-sm">
                            <span>{{ $l['label'] }}</span>
                            <span class="font-medium">{{ number_format($l['used']) }} / {{ $l['max'] === null ? 'unlimited' : number_format($l['max']) }}</span>
                        </div>
                        @if ($l['max'] !== null)
                            <div class="mt-1 h-2 w-full rounded-full bg-gray-200 dark:bg-white/10">
                                <div class="h-2 rounded-full {{ $pct >= 100 ? 'bg-danger-500' : ($pct >= 80 ? 'bg-warning-500' : 'bg-primary-500') }}" style="width: {{ $pct }}%"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Modules</x-slot>
            <ul class="space-y-1 text-sm">
                @foreach ($s['modules'] as $label => $on)
                    <li class="flex items-center gap-2">
                        <x-filament::icon :icon="$on ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle'" class="h-5 w-5 {{ $on ? 'text-success-600' : 'text-gray-400' }}" />
                        <span @class(['text-gray-400 line-through' => ! $on])>{{ $label }}</span>
                    </li>
                @endforeach
                <li class="pt-2 text-xs text-gray-500">Masters, administration, security, dashboard and reports and the Office Desk are in every plan.</li>
            </ul>
            <p class="mt-4 text-sm text-gray-500">To change your plan, contact us.</p>
        </x-filament::section>
    </div>

    @if (! $s['customer']->isLifetime())
        <x-filament::section>
            <x-slot name="heading">Invoices</x-slot>
            @include('filament.partials.invoice-table', ['invoices' => $this->invoices()])
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Ways to pay</x-slot>
            @include('filament.partials.payment-methods')
        </x-filament::section>
    @endif
</x-filament-panels::page>
