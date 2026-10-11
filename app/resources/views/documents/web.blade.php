<!doctype html>
<html lang="{{ $p->language === 'ur' ? 'ur' : 'en' }}" @if ($p->rtl()) dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $p->titleEn($doc['title']) }} {{ $doc['number'] ?? '' }} — {{ $brand['name'] }}</title>
    <style>
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 12px; color: #1a1a1a; background: #f3f4f6; margin: 0; }
        .sheet { background: #ffffff; max-width: {{ $paper === 'a4_landscape' ? '277mm' : (str_starts_with($paper, 'thermal') ? ($paper === 'thermal58' ? '58mm' : '80mm') : ($small ? '190mm' : '186mm')) }}; margin: 16px auto; padding: 12mm; box-shadow: 0 1px 4px rgba(0,0,0,.15); position: relative; }
        .ur { font-family: "Jameel Noori Nastaleeq", "Noto Nastaliq Urdu", Lateef, serif; font-size: 130%; }
        table { border-collapse: collapse; width: 100%; }
        img { max-width: 100%; }
        .watermark { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 96px; font-weight: bold; color: rgba(0,0,0,.08); transform: rotate(-30deg); pointer-events: none; }
        .notice { border: 1px solid #c9a227; background: #fff8e1; padding: 4px 6px; }
        .letterhead td, .titlebar td { vertical-align: middle; }
        .titlebar { background: {{ $brand['green'] }}; color: #fff; }
        .rule { border-bottom: 2px solid {{ $brand['gold'] }}; margin: 2px 0 4px; }
        @media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0; max-width: none; } }
        @media (max-width: 640px) { .sheet { padding: 4mm; margin: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    @if ($watermark)
        <div class="watermark">{{ $watermark }}</div>
    @endif
    @include('documents.partials.body')
</div>
</body>
</html>
