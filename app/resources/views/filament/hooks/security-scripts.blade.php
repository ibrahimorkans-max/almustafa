@php
    $session = auth('web')->check() ? app(\App\Domain\Auth\ActorContext::class)->session() : null;
@endphp
<script src="{{ asset('js/passkeys.js') }}?v=1" defer></script>
@if ($session !== null && $session->location_source !== 'browser')
    <script src="{{ asset('js/session-location.js') }}?v=1" data-url="{{ route('filament.admin.session-location') }}" defer></script>
@endif
