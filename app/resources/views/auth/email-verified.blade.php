<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locales::isRtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ok ? __('E-mail confirmed') : __('Link not valid') }} - {{ config('ricemill.product.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f3f6f4;
            font-family: 'Noto Nastaliq Urdu', 'Noto Naskh Arabic', 'Segoe UI', Tahoma, Arial, sans-serif; color: #1d2329; padding: 16px; box-sizing: border-box; }
        .card { max-width: 440px; width: 100%; background: #fff; border-radius: 12px; padding: 28px; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .icon { font-size: 44px; line-height: 1; }
        h1 { font-size: 20px; margin: 14px 0 8px; color: {{ $ok ? '#0f4c3a' : '#b42318' }}; }
        p { line-height: 1.7; color: #4b5560; }
        a { display: inline-block; margin-top: 12px; background: #0f4c3a; color: #fff; text-decoration: none; padding: 10px 22px; border-radius: 8px; }
    </style>
</head>
<body>
<div class="card">
    <div class="icon">{{ $ok ? '✔' : '✖' }}</div>
    <h1>{{ $ok ? __('E-mail confirmed') : __('Link not valid') }}</h1>
    <p>{{ $message }}</p>
    <a href="{{ url('/admin') }}">{{ __('Open :product', ['product' => config('ricemill.product.name')]) }}</a>
</div>
</body>
</html>
