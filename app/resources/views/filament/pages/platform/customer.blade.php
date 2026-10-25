@php
    $customer = $this->customer();
    $p = $this->picture();
    $plan = $p['plan'];
    $rs = fn (int $v) => \App\Filament\Support\Units::paiseToRupees($v);
    $pk = fn ($at, $f = 'd M Y') => $at ? $at->copy()->timezone('Asia/Karachi')->format($f) : '—';
    $statuses = \App\Filament\Resources\Platform\CustomerResource::STATUSES;
    $limits = [
        ['Mills', $p['usage']['mills'], $plan?->max_mills],
        ['Staff users', $p['usage']['users'], $plan?->max_users],
        ['Vehicles this month', $p['usage']['vehicles'], $plan?->max_vehicles_month],
    ];
@endphp
<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">Company</x-slot>
            <dl class="space-y-1 text-sm">
                <div><dt class="inline text-gray-500">Contact:</dt> <dd class="inline">{{ $customer->contact_name ?? '—' }}</dd></div>
                <div><dt class="inline text-gray-500">Phone:</dt> <dd class="inline">{{ $customer->phone ?? '—' }}</dd></div>
                <div><dt class="inline text-gray-500">Email:</dt> <dd class="inline">{{ $customer->email ?? '—' }}</dd></div>
                @if ($customer->address)<div><dt class="inline text-gray-500">Address:</dt> <dd class="inline">{{ $customer->address }}</dd></div>@endif
                <div><dt class="inline text-gray-500">Came by:</dt> <dd class="inline">{{ match ($customer->source) { 'signup' => 'public sign-up', 'platform' => 'added by the platform', default => 'first installation' } }}</dd></div>
                <div><dt class="inline text-gray-500">Since:</dt> <dd class="inline">{{ $pk($customer->created_at) }}</dd></div>
                @if ($customer->notes)<div class="pt-2 text-gray-600 dark:text-gray-300">{{ $customer->notes }}</div>@endif
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Subscription</x-slot>
            <div class="text-xl font-bold">{{ $plan?->name ?? 'No plan: everything' }}</div>
            @if ($customer->isLifetime())
                <div class="mt-1 inline-flex items-center gap-1 rounded-full bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                    <x-filament::icon icon="heroicon-o-sparkles" class="h-4 w-4" /> Lifetime access since {{ $pk($customer->lifetime_since) }}
                </div>
            @endif
            @if ($plan)<div class="text-sm text-gray-500">{{ $rs($plan->price_paise_monthly) }} a month</div>@endif
            <div class="mt-2">
                <x-filament::badge :color="$customer->trialEnded() ? 'danger' : match ($customer->status) { 'active' => 'success', 'trial' => 'info', 'past_due' => 'warning', default => 'danger' }">
                    {{ $customer->trialEnded() ? 'Trial ended' : ($statuses[$customer->status] ?? $customer->status) }}
                </x-filament::badge>
                @if ($customer->status === 'trial' && $customer->trial_ends_at)
                    <span class="ml-1 text-sm text-gray-500">until {{ $pk($customer->trial_ends_at) }}</span>
                @endif
            </div>
            <dl class="mt-3 space-y-1 text-sm">
                <div><dt class="inline text-gray-500">Billed in all:</dt> <dd class="inline">{{ $rs($p['billed_paise']) }}</dd></div>
                <div><dt class="inline text-gray-500">Paid:</dt> <dd class="inline">{{ $rs($p['paid_paise']) }}</dd></div>
                <div><dt class="inline text-gray-500">Open:</dt> <dd @class(['inline font-semibold', 'text-danger-600 dark:text-danger-400' => $p['open_paise'] > 0])>{{ $rs($p['open_paise']) }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Usage</x-slot>
            <div class="space-y-3">
                @foreach ($limits as [$label, $used, $max])
                    @php($pct = $max ? min(100, (int) round($used * 100 / max(1, $max))) : 0)
                    <div>
                        <div class="flex justify-between text-sm"><span>{{ $label }}</span><span class="font-medium">{{ number_format($used) }} / {{ $max === null ? 'unlimited' : number_format($max) }}</span></div>
                        @if ($max !== null)
                            <div class="mt-1 h-2 w-full rounded-full bg-gray-200 dark:bg-white/10">
                                <div class="h-2 rounded-full {{ $pct >= 100 ? 'bg-danger-500' : ($pct >= 80 ? 'bg-warning-500' : 'bg-primary-500') }}" style="width: {{ $pct }}%"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Mills</x-slot>
            <table class="w-full text-sm">
                <thead><tr class="border-b text-left text-gray-500 dark:border-white/10"><th class="py-1 pr-2">Code</th><th class="py-1 pr-2">Name</th><th class="py-1 pr-2">City</th><th class="py-1 text-right">Active logins</th></tr></thead>
                <tbody>
                    @forelse ($p['mills'] as $mill)
                        <tr class="border-b dark:border-white/5"><td class="py-1 pr-2 font-medium">{{ $mill->code }}</td><td class="py-1 pr-2">{{ $mill->name }}</td><td class="py-1 pr-2">{{ $mill->city ?? '—' }}</td><td class="py-1 text-right">{{ $mill->staff_count }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-gray-500">No mills.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Owners</x-slot>
            <table class="w-full text-sm">
                <thead><tr class="border-b text-left text-gray-500 dark:border-white/10"><th class="py-1 pr-2">Username</th><th class="py-1 pr-2">Name</th><th class="py-1 pr-2">State</th><th class="py-1 text-right">Last sign-in</th></tr></thead>
                <tbody>
                    @forelse ($p['owners'] as $o)
                        <tr class="border-b dark:border-white/5">
                            <td class="py-1 pr-2 font-medium">{{ $o->username }}</td><td class="py-1 pr-2">{{ $o->person?->full_name }}</td>
                            <td class="py-1 pr-2">{{ $o->status }}{{ $o->hasMfa() ? '' : ', 2FA not set up' }}</td>
                            <td class="py-1 text-right">{{ $pk($o->last_login_at, 'd M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-gray-500">No Owner.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Invoices</x-slot>
        <table class="w-full text-sm">
            <thead><tr class="border-b text-left text-gray-500 dark:border-white/10"><th class="py-1 pr-2">Number</th><th class="py-1 pr-2">For</th><th class="py-1 pr-2 text-right">Amount</th><th class="py-1 pr-2">Due</th><th class="py-1 pr-2">State</th><th class="py-1"></th></tr></thead>
            <tbody>
                @forelse ($p['invoices'] as $inv)
                    @php($st = $inv->standing())
                    <tr class="border-b dark:border-white/5">
                        <td class="py-1 pr-2 font-medium">{{ $inv->number }}</td><td class="py-1 pr-2">{{ $inv->description }}</td>
                        <td class="py-1 pr-2 text-right">{{ $rs($inv->amount_paise) }}</td><td class="py-1 pr-2">{{ $pk($inv->due_at) }}</td>
                        <td class="py-1 pr-2">
                            <x-filament::badge :color="match ($st) { 'paid' => 'success', 'overdue' => 'danger', 'void' => 'gray', default => 'warning' }">{{ match ($st) { 'paid' => 'Paid', 'overdue' => 'Overdue', 'void' => 'Cancelled', default => 'Open' } }}</x-filament::badge>
                            @if ($inv->status === 'open' && $inv->reported_at)<span class="text-xs text-gray-500">payment reported</span>@endif
                        </td>
                        <td class="py-1 text-right"><a href="{{ route('filament.admin.saas.invoice', ['id' => $inv->id]) }}" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">PDF</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="mt-2 text-xs text-gray-500">Mark paid or cancel under <a class="underline" href="{{ \App\Filament\Resources\Platform\SaasInvoiceResource::getUrl('index', ['tableFilters' => ['customer_id' => ['value' => $customer->id]]]) }}">Platform → Invoices</a>.</p>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">History</x-slot>
        <ul class="space-y-1 text-sm">
            @forelse ($p['history'] as $h)
                <li><span class="text-gray-500">{{ $pk($h->occurred_at, 'd M Y H:i') }}</span> · {{ $h->action }}@if (! empty($h->meta['reason'])) — {{ $h->meta['reason'] }}@endif</li>
            @empty
                <li class="text-gray-500">Nothing recorded.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
