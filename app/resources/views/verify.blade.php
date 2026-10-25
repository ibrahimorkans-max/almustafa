@php($brand = config('ricemill.product'))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verify a document · {{ $brand['name'] }}</title>
    <link rel="icon" type="image/png" href="{{ asset($brand['favicon']) }}">
    <meta name="theme-color" content="{{ $brand['green'] }}">
    <style>
        :root { --green: {{ $brand['green'] }}; --gold: {{ $brand['gold'] }}; }
        body { font-family: system-ui, "Segoe UI", sans-serif; max-width: 36rem; margin: 0 auto; padding: 1.25rem 1rem 3rem; color: #172019; background: #fff; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; border-bottom: 3px solid var(--gold); padding-bottom: .75rem; }
        header img { height: 48px; width: auto; }
        header .contact { text-align: right; font-size: .85rem; color: #4b5a50; }
        h1 { color: var(--green); font-size: 1.35rem; margin: 1.25rem 0 .25rem; }
        .ur { font-family: "Noto Nastaliq Urdu", "Jameel Noori Nastaleeq", serif; font-size: 1.1rem; color: #4b5a50; }
        label { display: block; margin-top: .9rem; font-weight: 600; }
        input { width: 100%; padding: .6rem; font-size: 1rem; box-sizing: border-box; border: 1px solid #b8c0c8; border-radius: .4rem; }
        button { margin-top: 1.1rem; padding: .65rem 1.4rem; font-size: 1rem; background: var(--green); color: #fff; border: 0; border-radius: .4rem; cursor: pointer; }
        .ok { border-left: 4px solid #15803d; background: #f0fdf4; padding: .75rem 1rem; margin-top: 1.5rem; }
        .bad { border-left: 4px solid #b91c1c; background: #fef2f2; padding: .75rem 1rem; margin-top: 1.5rem; }
        dt { font-weight: 600; } dd { margin: 0 0 .4rem; }
        footer { margin-top: 2.5rem; font-size: .8rem; color: #6b7280; }
    </style>
</head>
<body>
    <header>
        <img src="{{ asset($brand['logo']) }}" alt="{{ $brand['name'] }}">
        <div class="contact">{{ $brand['tagline'] }}</div>
    </header>
    <h1>Verify a document</h1>
    <div class="ur" lang="ur" dir="rtl">دستاویز کی تصدیق</div>
    <p>Enter the number and the verification code printed on a weighment slip, invoice, gate pass, purchase bill or payment voucher — or scan the QR code on it.</p>
    <form method="get" action="{{ url('/verify') }}">
        <label for="doc">Document number</label>
        <input id="doc" name="doc" value="{{ $doc }}" maxlength="60" required>
        <label for="code">Verification code</label>
        <input id="code" name="code" value="{{ $code }}" maxlength="20" placeholder="XXXX-XXXX-XXXX" required>
        <button type="submit">Verify</button>
    </form>

    @if ($asked)
        @if ($result)
            <div class="ok">
                <strong>Genuine {{ strtolower($result['type']) }}.</strong> These are the figures on record:
                <dl>
                    @foreach ($result['facts'] as $label => $value)
                        <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                    @endforeach
                </dl>
                If your printout shows different figures, it has been altered.
            </div>
        @else
            <div class="bad"><strong>Not verified.</strong> No document with this number and code exists. Check both, or treat the printout as suspect and call the company that issued it.</div>
        @endif
    @endif
    <footer>Document check by {{ $brand['name'] }} · {{ $brand['tagline'] }}</footer>
</body>
</html>
