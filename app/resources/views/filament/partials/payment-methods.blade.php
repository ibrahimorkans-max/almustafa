{{-- The ways to pay the product owner, each with its QR code. Falls back to the bank details in .env when none are set up. --}}
@php
    $methods = \App\Models\SaasPaymentMethod::query()->offered()->get();
    $bank = \App\Filament\Pages\SubscriptionPage::bankDetails();
@endphp
@if ($methods->isEmpty())
    <div class="rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/5">
        <div class="font-semibold">Pay by bank transfer</div>
        @forelse ($bank as $label => $value)
            <div>{{ $label }}: <span class="font-medium">{{ $value }}</span></div>
        @empty
            <div class="text-gray-500">Contact us for the payment details.</div>
        @endforelse
    </div>
@else
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($methods as $m)
            <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-base font-semibold">{{ $m->name }}</div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ \App\Models\SaasPaymentMethod::KINDS[$m->kind] ?? $m->kind }}</div>
                    </div>
                    <x-filament::icon :icon="match ($m->kind) { 'wallet' => 'heroicon-o-device-phone-mobile', 'raast' => 'heroicon-o-bolt', 'bank' => 'heroicon-o-building-library', default => 'heroicon-o-banknotes' }" class="h-6 w-6 text-primary-500" />
                </div>
                @if ($m->qrDataUri())
                    <div class="mt-3 flex justify-center">
                        <img src="{{ $m->qrDataUri() }}" alt="QR code for {{ $m->name }}" class="h-44 w-44 rounded-lg border border-gray-200 bg-white object-contain p-2 dark:border-white/10">
                    </div>
                    <div class="mt-1 text-center text-xs text-gray-500">Scan with your banking or wallet app</div>
                @endif
                <dl class="mt-3 space-y-1 text-sm">
                    @foreach ($m->details() as $label => $value)
                        <div class="flex justify-between gap-2"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-right font-medium" style="word-break: break-all;">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                @if ($m->instructions)
                    <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">{{ $m->instructions }}</p>
                @endif
            </div>
        @endforeach
    </div>
@endif
<p class="mt-3 text-sm text-gray-500">Write the invoice number with your payment, then press <strong>I've paid</strong> on the invoice.</p>
