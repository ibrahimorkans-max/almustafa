{{--
    A customer's invoices as cards: number, what for, amount, due date and state, the PDF, "I've paid", and
    the payment it reported (transaction ID, how, how much, when) while it is checked, or the payment
    received once it is. Needs $invoices and the page's reportPaymentAction.
--}}
@php($rs = fn (?int $p) => \App\Filament\Support\Units::paiseToRupees((int) $p))
@if ($invoices->isEmpty())
    <p class="text-sm text-gray-500">No invoices yet.</p>
@else
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($invoices as $inv)
            @php($standing = $inv->standing())
            <div @class([
                'flex flex-col rounded-xl border bg-white p-4 shadow-sm dark:bg-gray-900',
                'border-danger-300 dark:border-danger-700' => $standing === 'overdue',
                'border-success-300 dark:border-success-700' => $standing === 'paid',
                'border-gray-200 dark:border-white/10' => ! in_array($standing, ['overdue', 'paid'], true),
            ])>
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-semibold">{{ $inv->number }}</div>
                        <div class="truncate text-sm text-gray-500">{{ $inv->description }}</div>
                    </div>
                    <x-filament::badge :color="match ($standing) { 'paid' => 'success', 'overdue' => 'danger', default => ($inv->reported_at ? 'info' : 'warning') }">
                        {{ match (true) { $standing === 'paid' => 'Paid', $inv->status === 'open' && $inv->reported_at !== null => 'Being checked', $standing === 'overdue' => 'Overdue', default => 'Due' } }}
                    </x-filament::badge>
                </div>

                <div class="mt-3 flex items-end justify-between gap-2">
                    <div class="text-2xl font-bold">{{ $rs($inv->amount_paise) }}</div>
                    <div class="text-right text-xs text-gray-500">Due {{ $inv->due_at->timezone('Asia/Karachi')->format('d M Y') }}</div>
                </div>

                @if ($inv->status === 'paid')
                    <div class="mt-3 rounded-lg bg-success-50 p-3 text-sm dark:bg-success-950">
                        <div class="font-medium text-success-800 dark:text-success-200">Payment received {{ $inv->paid_at?->timezone('Asia/Karachi')->format('d M Y') }}</div>
                        @if ($inv->payment_reference)
                            <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">Transaction ID <span class="font-mono font-semibold">{{ $inv->payment_reference }}</span> · {{ $rs($inv->paid_amount_paise) }}</div>
                        @endif
                    </div>
                @elseif ($inv->status === 'open' && $inv->reported_at)
                    <div class="mt-3 rounded-lg bg-info-50 p-3 text-sm dark:bg-info-950">
                        <div class="font-medium text-info-800 dark:text-info-200">You reported a payment {{ $inv->reported_at->timezone('Asia/Karachi')->format('d M') }}: being checked</div>
                        <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 text-xs text-gray-600 dark:text-gray-300">
                            <dt>Transaction ID</dt><dd class="font-mono font-semibold" style="word-break: break-all;">{{ $inv->reported_reference }}</dd>
                            @if ($inv->reportedMethod)<dt>Paid with</dt><dd>{{ $inv->reportedMethod->name }}</dd>@endif
                            @if ($inv->reported_amount_paise !== null)<dt>Amount</dt><dd>{{ $rs($inv->reported_amount_paise) }}</dd>@endif
                            @if ($inv->reported_paid_on)<dt>Paid on</dt><dd>{{ $inv->reported_paid_on->format('d M Y') }}</dd>@endif
                        </dl>
                    </div>
                @endif

                <div class="mt-4 flex items-center justify-between gap-2">
                    <a href="{{ route('filament.admin.saas.invoice', ['id' => $inv->id]) }}" target="_blank" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Invoice PDF</a>
                    @if ($inv->status === 'open')
                        {{ ($this->reportPaymentAction)(['invoice' => $inv->id]) }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
