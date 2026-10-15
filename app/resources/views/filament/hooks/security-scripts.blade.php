@php
    $session = auth('web')->check() ? app(\App\Domain\Auth\ActorContext::class)->session() : null;
@endphp
<script src="{{ asset('js/passkeys.js') }}?v=1" defer></script>
<script src="{{ asset('js/page-expiry.js') }}?v=1"></script>
@if (auth('web')->check() && app(\App\Domain\Settings\SettingsService::class)->screenGuard(app(\App\Domain\Tenancy\TenantContext::class)->entityId())['on'])
    <script src="{{ asset('js/screen-guard.js') }}?v=1" data-label="{{ auth('web')->user()->username }} · {{ request()->ip() }}"></script>
@endif
@if ($session !== null && $session->location_source !== 'browser')
    <script src="{{ asset('js/session-location.js') }}?v=1" data-url="{{ route('filament.admin.session-location') }}" defer></script>
@endif
