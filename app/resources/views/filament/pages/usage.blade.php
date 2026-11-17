<x-filament-panels::page>
    @php
        $r = $this->report();
        $t = $r->totals();
        $n = fn ($v) => number_format((float) $v, 2);
        $platform = $this->forPlatform();
        $channels = \App\Models\Usage\ServiceUsage::CHANNELS;
        $sel = 'fi-select-input block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5';
    @endphp

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <label class="text-sm"><span class="text-gray-500">{{ __('Period') }}</span>
            <select wire:model.live="period" class="{{ $sel }}">@foreach (\App\Domain\Usage\UsageReport::PERIODS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
        @if ($period === 'custom')
            <label class="text-sm"><span class="text-gray-500">{{ __('From') }}</span><input type="date" wire:model.live="from" class="{{ $sel }}"></label>
            <label class="text-sm"><span class="text-gray-500">{{ __('To') }}</span><input type="date" wire:model.live="to" class="{{ $sel }}"></label>
        @endif
        <label class="text-sm"><span class="text-gray-500">{{ __('Channel') }}</span>
            <select wire:model.live="channel" class="{{ $sel }}"><option value="">{{ __('All') }}</option>@foreach ($channels as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
        @if ($platform)
            <label class="text-sm"><span class="text-gray-500">{{ __('Company') }}</span>
                <select wire:model.live="customer" class="{{ $sel }}"><option value="">{{ __('All companies') }}</option>
                    @foreach (app(\App\Domain\Tenancy\TenantContext::class)->runAsSystem(fn () => \App\Models\Customer::query()->whereNotIn('id', \App\Domain\Saas\PlatformCompany::customerIds())->orderBy('name')->pluck('name', 'id')) as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select></label>
        @endif
        <label class="text-sm"><span class="text-gray-500">{{ __('Mill') }}</span>
            <select wire:model.live="entity" class="{{ $sel }}"><option value="">{{ __('All mills') }}</option>@foreach ($this->millOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['API calls', $t['api'] ?? 0, 'heroicon-o-cpu-chip'], ['SMS sent', $t['sms'] ?? 0, 'heroicon-o-device-phone-mobile'],
            ['WhatsApp messages', $t['whatsapp'] ?? 0, 'heroicon-o-chat-bubble-left-right'], ['E-mails sent', $t['email'] ?? 0, 'heroicon-o-envelope'],
            ['Successful', $t['ok'] ?? 0, 'heroicon-o-check-circle'], ['Failed', $t['failed'] ?? 0, 'heroicon-o-x-circle'],
            ['Estimated cost', $n($t['estimated'] ?? 0), 'heroicon-o-calculator'], [$platform ? 'Charged to companies' : 'Charged', $n($t['charged'] ?? 0), 'heroicon-o-banknotes'],
        ] as [$label, $value, $icon])
            <x-filament::section compact>
                <div class="flex items-center gap-3">
                    <x-filament::icon :icon="$icon" class="h-6 w-6 text-primary-500" />
                    <div><div class="text-xs text-gray-500">{{ $label }}</div><div class="text-xl font-semibold">{{ $value }}</div></div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
    <p class="-mt-2 text-xs text-gray-500">
        Costs are in the price list's currency (PKR unless set otherwise). Actual cost as reported by providers: USD {{ $n($t['actual_usd'] ?? 0) }}{{ (float) ($t['actual_other'] ?? 0) > 0 ? ' · other '.$n($t['actual_other']) : '' }}.
        @if ($platform) Billed by providers in this period: {{ $n($t['provider_billed'] ?? 0) }} · not yet reconciled (estimated − billed): {{ $n($t['unreconciled'] ?? 0) }}. @endif
    </p>

    @php($groups = array_filter(['customer' => $platform ? 'By company' : null, 'entity' => 'By mill', 'provider' => 'By provider', 'category' => 'By kind of service', 'module' => 'By module', 'day' => 'By day']))
    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ($groups as $g => $heading)
            <x-filament::section :heading="$heading" collapsible compact>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="py-1 pr-2">{{ __('Name') }}</th><th class="pr-2 text-right">{{ __('API') }}</th><th class="pr-2 text-right">{{ __('SMS') }}</th><th class="pr-2 text-right">{{ __('WhatsApp') }}</th><th class="pr-2 text-right">{{ __('E-mail') }}</th><th class="pr-2 text-right">{{ __('Failed') }}</th><th class="pr-2 text-right">{{ __('Estimated') }}</th><th class="text-right">{{ __('Charged') }}</th></tr></thead>
                        <tbody>
                            @forelse ($r->by($g) as $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1 pr-2">
                                        @if ($g === 'customer' && $row['key'])
                                            <button type="button" class="text-primary-600 hover:underline" wire:click="showCompany('{{ $row['key'] }}')">{{ $row['name'] }}</button>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                    </td>
                                    <td class="pr-2 text-right">{{ $row['api'] }}</td><td class="pr-2 text-right">{{ $row['sms'] }}</td><td class="pr-2 text-right">{{ $row['whatsapp'] }}</td>
                                    <td class="pr-2 text-right">{{ $row['email'] }}</td><td class="pr-2 text-right">{{ $row['failed'] }}</td>
                                    <td class="pr-2 text-right">{{ $n($row['estimated']) }}</td><td class="text-right">{{ $n($row['charged']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="py-2 text-gray-500">{{ __('No usage in this period.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Usage records" description="Each use of an outside service, newest first (the latest 200; the export has them all)." collapsible>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead><tr class="text-left text-gray-500"><th class="py-1 pr-2">{{ __('Usage ID') }}</th><th class="pr-2">{{ __('When (PKT)') }}</th><th class="pr-2">{{ __('Mill') }}</th><th class="pr-2">{{ __('Service') }}</th><th class="pr-2">{{ __('Module · action') }}</th><th class="pr-2">{{ __('Why') }}</th><th class="pr-2">{{ __('By') }}</th><th class="pr-2">{{ __('To') }}</th><th class="pr-2 text-right">{{ __('Qty') }}</th><th class="pr-2 text-right">{{ __('Cost') }}</th><th class="pr-2">{{ __('Status') }}</th><th>{{ __('Billing') }}</th></tr></thead>
                <tbody>
                    @forelse ($r->records() as $u)
                        <tr class="border-t border-gray-100 align-top dark:border-white/5">
                            <td class="py-1 pr-2 font-mono">{{ $u->reference }}</td>
                            <td class="pr-2 whitespace-nowrap">{{ $u->occurred_at->timezone('Asia/Karachi')->format('d M Y H:i:s') }}</td>
                            <td class="pr-2">{{ $u->entity?->name ?? '—' }}</td>
                            <td class="pr-2">{{ $channels[$u->channel] ?? $u->channel }} · {{ $u->provider }}<br><span class="text-gray-500">{{ $u->service }}</span></td>
                            <td class="pr-2">{{ $u->module }}<br><span class="text-gray-500">{{ $u->action }}</span></td>
                            <td class="pr-2">{{ $u->description }}</td>
                            <td class="pr-2">{{ $u->byLabel() }}</td>
                            <td class="pr-2">{{ $u->recipient_masked ?? '' }}</td>
                            <td class="pr-2 text-right">{{ $u->quantity }}{{ $u->units ? ' ('.$u->units.' tokens)' : '' }}</td>
                            <td class="pr-2 text-right whitespace-nowrap">{{ $n($u->estimated_cost) }} {{ $u->currency }}@if ($u->actual_cost !== null)<br><span class="text-gray-500">actual {{ $u->actual_cost }} {{ $u->actual_currency }}</span>@endif</td>
                            <td class="pr-2"><x-filament::badge :color="match ($u->deliveryStatus()) { 'failed' => 'danger', 'delivered', 'read' => 'success', default => 'gray' }">{{ $u->deliveryStatus() }}</x-filament::badge></td>
                            <td>{{ $u->billing_status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="py-2 text-gray-500">{{ __('No usage in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
