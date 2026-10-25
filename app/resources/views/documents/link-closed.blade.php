<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Link closed — {{ config('ricemill.product.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f3f4f6; color: #1a1a1a; margin: 0; padding: 16px; }
        .card { max-width: 480px; margin: 10vh auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.12); text-align: center; }
        .ur { font-family: "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", serif; font-size: 1.2em; direction: rtl; }
        img { height: 56px; }
    </style>
</head>
<body>
<div class="card">
    <img src="{{ asset(config('ricemill.product.mark')) }}" alt="" style="height: 64px; width: auto;">
    <h1 style="font-size: 1.2em;">This link is no longer open</h1>
    <p>It has expired, was used the allowed number of times, or was withdrawn. Ask the mill's HR office for a new one.</p>
    <p class="ur">یہ لنک اب کھلا نہیں ہے۔ نئے لنک کے لیے مل کے ایچ آر دفتر سے رابطہ کریں۔</p>
    <p style="color: #5b6470;">Ask the company that sent it for a new link. · {{ config('ricemill.product.name') }}</p>
</div>
</body>
</html>
