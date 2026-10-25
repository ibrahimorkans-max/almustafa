<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Under maintenance — {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset(config('ricemill.product.favicon')) }}">
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --fg: #0f172a; --muted: #475569; --card: #ffffff; --line: #e2e8f0; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f172a; --fg: #f1f5f9; --muted: #94a3b8; --card: #1e293b; --line: #334155; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--fg);
               font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 16px; box-sizing: border-box; }
        main { max-width: 32rem; width: 100%; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
        h1 { margin: 0 0 8px; font-size: 1.25rem; }
        p { margin: 8px 0; }
        .muted { color: var(--muted); font-size: .9rem; }
    </style>
</head>
<body>
<main>
    <picture style="display:block;margin-bottom:12px">
        <source srcset="{{ asset(config('ricemill.product.logo_dark')) }}" media="(prefers-color-scheme: dark)">
        <img src="{{ asset(config('ricemill.product.logo')) }}" alt="{{ config('ricemill.product.name') }}" style="height:48px;width:auto">
    </picture>
    <h1>Under maintenance</h1>
    <p>{{ $message }}</p>
    <p><strong>Expected back at {{ $back->format('g:i A') }} PKT{{ $back->isToday() ? '' : ' on '.$back->format('d M Y') }}.</strong></p>
    <p class="muted">Phones, tablets and the weighbridge keep recording offline and will sync automatically afterwards. Nothing is lost.</p>
</main>
</body>
</html>
