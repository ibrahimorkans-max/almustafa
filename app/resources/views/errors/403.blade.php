@php
    $user = auth('web')->user();
    $role = $user instanceof \App\Models\LoginAccount ? $user->role?->name : null;
    $detail = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $detail = in_array($detail, ['', 'Forbidden', 'This action is unauthorized.'], true) ? null : $detail;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No access — {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset(config('ricemill.brand.favicon')) }}">
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --fg: #0f172a; --muted: #475569; --card: #ffffff; --line: #e2e8f0; --link: #b45309; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f172a; --fg: #f1f5f9; --muted: #94a3b8; --card: #1e293b; --line: #334155; --link: #fbbf24; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--fg);
               font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 16px; box-sizing: border-box; }
        main { max-width: 32rem; width: 100%; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
        h1 { margin: 0 0 8px; font-size: 1.25rem; }
        p { margin: 8px 0; }
        .muted { color: var(--muted); font-size: .9rem; }
        a { color: var(--link); }
    </style>
</head>
<body>
<main>
    <picture style="display:block;margin-bottom:12px">
        <source srcset="{{ asset(config('ricemill.brand.logo_wide_dark')) }}" media="(prefers-color-scheme: dark)">
        <img src="{{ asset(config('ricemill.brand.logo_wide')) }}" alt="{{ config('ricemill.brand.name') }}" style="height:56px;width:auto">
    </picture>
    <h1>You do not have access to this page</h1>
    @if ($detail)
        <p>{{ $detail }}</p>
    @endif
    @if ($role)
        <p>Your role, <strong>{{ $role }}</strong>, does not include the permission this page needs.</p>
    @endif
    <p class="muted">If you need it for your work, ask an administrator. Role changes need the approval of a second person.</p>
    <p><a href="{{ url('/admin') }}">Back to the dashboard</a></p>
</main>
</body>
</html>
