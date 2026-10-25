@php
    $reason = $this->reason();
    $customer = $this->customer();
    $open = $this->invoices(openOnly: true);
    $total = (int) $open->sum('amount_paise');
    $contact = $this->contact();
    $owner = $this->canReportPayment();
@endphp
<x-filament-panels::page.simple>
    <div class="space-y-6">
    <div class="overflow-hidden rounded-2xl border border-danger-200 bg-gradient-to-br from-danger-50 to-white shadow-sm dark:border-danger-800 dark:from-danger-950 dark:to-gray-900">
        <div class="flex flex-wrap items-center gap-4 p-6">
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-danger-100 dark:bg-danger-900">
                <x-filament::icon :icon="$reason === 'trial_ended' ? 'heroicon-o-clock' : 'heroicon-o-lock-closed'" class="h-8 w-8 text-danger-600 dark:text-danger-300" />
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-xl font-bold text-danger-800 dark:text-danger-200">
                    {{ $reason === 'trial_ended' ? 'Your free trial has ended' : 'Your account is blocked' }}
                </h2>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    {{ $customer?->name }}: everything you recorded is safe, but nothing can be seen or changed until then.
                    @if ($owner)
                        Pay the amount below with any of the ways shown, then press <strong>I've paid</strong>. Your system opens again as soon as we confirm the payment.
                    @else
                        The system opens again once your company's owner has paid. Please tell them.
                    @endif
                </p>
            </div>
            @if ($owner && $total > 0)
                <div class="text-right">
                    <div class="text-xs uppercase tracking-wide text-gray-500">To pay</div>
                    <div class="text-3xl font-bold">{{ \App\Filament\Support\Units::paiseToRupees($total) }}</div>
                </div>
            @endif
        </div>
    </div>

    @if ($owner)
    <x-filament::section icon="heroicon-o-document-text">
        <x-slot name="heading">What is due</x-slot>
        @if ($open->isEmpty())
            <p class="text-sm text-gray-500">No invoice is open. {{ $contact === '' ? 'Please contact us to continue.' : 'Please contact us to continue: '.$contact.'.' }}</p>
        @else
            @include('filament.partials.invoice-table', ['invoices' => $open])
        @endif
    </x-filament::section>

    <x-filament::section icon="heroicon-o-qr-code">
        <x-slot name="heading">Ways to pay</x-slot>
        @include('filament.partials.payment-methods')
    </x-filament::section>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-500">
        <span>@if ($contact !== '') Questions? {{ $contact }} @endif</span>
        <form method="post" action="{{ filament()->getLogoutUrl() }}">
            @csrf
            <x-filament::button type="submit" color="gray" icon="heroicon-o-arrow-left-on-rectangle">Sign out</x-filament::button>
        </form>
    </div>
    </div>
</x-filament-panels::page.simple>
