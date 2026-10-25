@php
    $user = filament()->auth()->user();
    $banner = $user instanceof \App\Models\LoginAccount ? \App\Filament\Pages\SubscriptionPage::banner($user) : null;
@endphp
@if ($banner)
    <div @class([
        'mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-3 text-sm',
        'border-danger-300 bg-danger-50 text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200' => $banner['color'] === 'danger',
        'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200' => $banner['color'] === 'warning',
        'border-info-300 bg-info-50 text-info-800 dark:border-info-700 dark:bg-info-950 dark:text-info-200' => $banner['color'] === 'info',
    ])>
        <span><strong>{{ $banner['title'] }}</strong> {{ $banner['body'] }}</span>
        @if ($banner['link'])
            <a href="{{ $banner['link'] }}" class="font-semibold underline">Pay or report a payment</a>
        @endif
    </div>
@endif
@if ($user instanceof \App\Models\LoginAccount && app(\App\Domain\Saas\CustomerAccess::class)->blocked($user))
    {{-- Blocked: the bell's notices hold the company's data (its calls are refused too, see PaymentGate). --}}
    <style>.fi-topbar-database-notifications-btn, .fi-topbar .fi-dropdown:has(.fi-topbar-database-notifications-btn) { display: none !important; }</style>
@endif
