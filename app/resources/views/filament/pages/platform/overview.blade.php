@php
    $stats = $this->stats();
    $reported = $stats->reported();
    $overdue = $stats->overdue();
    $trials = $stats->trialsEnding();
    $near = $stats->nearLimits();
    $newest = $stats->newest();
    $rs = fn (int $p) => \App\Filament\Support\Units::paiseToRupees($p);
    $customerUrl = fn ($c) => \App\Filament\Resources\Platform\CustomerResource::getUrl('view', ['record' => $c]);
    $invoicesUrl = \App\Filament\Resources\Platform\SaasInvoiceResource::getUrl();
    $initials = fn (string $name) => mb_strtoupper(mb_substr(collect(preg_split('/\s+/', trim($name)))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode(''), 0, 2));
@endphp
<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Payments to check --}}
        <x-filament::section icon="heroicon-o-inbox-arrow-down" icon-color="warning">
            <x-slot name="heading">Payments to check</x-slot>
            <x-slot name="description">Customers say they have paid: check the bank, then mark paid</x-slot>
            <x-slot name="headerEnd"><x-filament::link :href="$invoicesUrl.'?'.http_build_query(['tableFilters' => ['reported' => ['isActive' => true]]])" size="sm">Open</x-filament::link></x-slot>
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($reported as $inv)
                    <li class="flex items-center gap-3 py-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-warning-100 text-xs font-bold text-warning-700 dark:bg-warning-900 dark:text-warning-200">{{ $initials($inv->customer->name) }}</div>
                        <div class="min-w-0 flex-1">
                            <a href="{{ $customerUrl($inv->customer) }}" class="block truncate font-medium hover:underline">{{ $inv->customer->name }}</a>
                            <div class="truncate text-xs text-gray-500">{{ $inv->number }} · <span class="font-mono font-semibold">{{ $inv->reported_reference }}</span>{{ $inv->reportedMethod ? ' · '.$inv->reportedMethod->name : '' }} · paid {{ $inv->reported_paid_on?->format('d M') }}</div>
                        </div>
                        <div class="text-sm font-semibold">{{ $rs($inv->amount_paise) }}</div>
                    </li>
                @empty
                    <li class="flex items-center gap-2 py-6 text-sm text-gray-500"><x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" /> Nothing waiting to be checked.</li>
                @endforelse
            </ul>
        </x-filament::section>

        {{-- Overdue --}}
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">Overdue invoices</x-slot>
            <x-slot name="description">Oldest first</x-slot>
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($overdue as $inv)
                    <li class="flex items-center gap-3 py-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-danger-100 text-xs font-bold text-danger-700 dark:bg-danger-900 dark:text-danger-200">{{ $initials($inv->customer->name) }}</div>
                        <div class="min-w-0 flex-1">
                            <a href="{{ $customerUrl($inv->customer) }}" class="block truncate font-medium hover:underline">{{ $inv->customer->name }}</a>
                            <div class="truncate text-xs text-gray-500">{{ $inv->number }} · {{ $inv->daysOverdue() }} days late{{ $inv->customer->status === 'past_due' ? ' · read-only' : '' }}{{ $inv->customer->status === 'suspended' ? ' · blocked' : '' }}</div>
                        </div>
                        <div class="text-sm font-semibold text-danger-600 dark:text-danger-400">{{ $rs($inv->amount_paise) }}</div>
                    </li>
                @empty
                    <li class="flex items-center gap-2 py-6 text-sm text-gray-500"><x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" /> No overdue invoices.</li>
                @endforelse
            </ul>
        </x-filament::section>

        {{-- Trials ending --}}
        <x-filament::section icon="heroicon-o-clock" icon-color="info">
            <x-slot name="heading">Trials ending within 7 days</x-slot>
            <x-slot name="description">A good moment to call</x-slot>
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($trials as $t)
                    <li class="flex items-center gap-3 py-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-info-100 text-xs font-bold text-info-700 dark:bg-info-900 dark:text-info-200">{{ $initials($t->name) }}</div>
                        <div class="min-w-0 flex-1">
                            <a href="{{ $customerUrl($t) }}" class="block truncate font-medium hover:underline">{{ $t->name }}</a>
                            <div class="truncate text-xs text-gray-500">{{ $t->contact_name }} {{ $t->phone }}</div>
                        </div>
                        <x-filament::badge :color="$t->trialEnded() ? 'danger' : 'info'">
                            {{ $t->trialEnded() ? 'Ended '.$t->trial_ends_at->timezone('Asia/Karachi')->format('d M') : $t->trialDaysLeft().' days left' }}
                        </x-filament::badge>
                    </li>
                @empty
                    <li class="py-6 text-sm text-gray-500">No trials ending soon.</li>
                @endforelse
            </ul>
        </x-filament::section>

        {{-- Near a limit --}}
        <x-filament::section icon="heroicon-o-arrow-trending-up" icon-color="success">
            <x-slot name="heading">Ready for a bigger plan</x-slot>
            <x-slot name="description">Using 80 % or more of a limit</x-slot>
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($near as $row)
                    @php($pct = min(100, (int) round($row['used'] * 100 / max(1, $row['max']))))
                    <li class="py-3">
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <a href="{{ $customerUrl($row['customer']) }}" class="truncate font-medium hover:underline">{{ $row['customer']->name }}</a>
                            <span class="shrink-0 text-gray-500">{{ $row['label'] }}: <strong @class(['text-danger-600 dark:text-danger-400' => $pct >= 100])>{{ number_format($row['used']) }} / {{ number_format($row['max']) }}</strong></span>
                        </div>
                        <div class="mt-1 h-1.5 w-full rounded-full bg-gray-200 dark:bg-white/10"><div class="h-1.5 rounded-full {{ $pct >= 100 ? 'bg-danger-500' : 'bg-warning-500' }}" style="width: {{ $pct }}%"></div></div>
                    </li>
                @empty
                    <li class="py-6 text-sm text-gray-500">No customer is near a limit.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>

    {{-- Newest --}}
    <x-filament::section icon="heroicon-o-sparkles">
        <x-slot name="heading">Newest customers</x-slot>
        <x-slot name="headerEnd"><x-filament::link :href="\App\Filament\Resources\Platform\CustomerResource::getUrl()" size="sm">All customers</x-filament::link></x-slot>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @forelse ($newest as $n)
                <a href="{{ $customerUrl($n) }}" class="flex items-center gap-3 rounded-xl border border-gray-200 p-3 transition hover:border-primary-400 hover:shadow-sm dark:border-white/10">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700 dark:bg-primary-900 dark:text-primary-200">{{ $initials($n->name) }}</div>
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ $n->name }}</div>
                        <div class="truncate text-xs text-gray-500">
                            {{ $n->isLifetime() ? 'Lifetime' : ($n->plan?->name ?? 'No plan') }} · {{ $n->source === 'signup' ? 'signed up' : ($n->source === 'platform' ? 'added by you' : 'first installation') }} · {{ $n->created_at->timezone('Asia/Karachi')->format('d M') }}
                        </div>
                    </div>
                </a>
            @empty
                <p class="text-sm text-gray-500">No customers yet.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
