<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verify a document</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 34rem; margin: 2rem auto; padding: 0 1rem; color: #111; }
        label { display: block; margin-top: .75rem; font-weight: 600; }
        input { width: 100%; padding: .5rem; font-size: 1rem; box-sizing: border-box; }
        button { margin-top: 1rem; padding: .6rem 1.2rem; font-size: 1rem; }
        .ok { border-left: 4px solid #15803d; background: #f0fdf4; padding: .75rem 1rem; margin-top: 1.5rem; }
        .bad { border-left: 4px solid #b91c1c; background: #fef2f2; padding: .75rem 1rem; margin-top: 1.5rem; }
        dt { font-weight: 600; } dd { margin: 0 0 .4rem; }
    </style>
</head>
<body>
    <h1>Verify a document</h1>
    <p>Enter the number and the verification code printed on a weighment slip, tax invoice or gate pass.</p>
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
            <div class="bad"><strong>Not verified.</strong> No document with this number and code exists. Check both, or treat the printout as suspect.</div>
        @endif
    @endif
</body>
</html>
