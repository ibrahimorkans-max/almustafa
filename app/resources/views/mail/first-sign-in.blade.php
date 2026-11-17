<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f6f4;font-family:Segoe UI,Tahoma,Arial,sans-serif;color:#1d2329">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6f4;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;padding:28px">
    <tr><td style="font-size:20px;font-weight:bold;color:#0f4c3a">{{ $product }}</td></tr>
    <tr><td style="padding-top:16px;font-size:15px;line-height:1.6">
        {{ __('Hello!') }}<br>
        {{ $intro }}
    </td></tr>
    <tr><td style="padding:22px 0" align="center">
        <div dir="ltr" style="display:inline-block;background:#eef6f1;border:2px dashed #0f4c3a;border-radius:10px;padding:14px 26px;font-size:34px;font-weight:bold;letter-spacing:10px;color:#0f4c3a;font-family:Consolas,Menlo,monospace">{{ $code }}</div>
    </td></tr>
    <tr><td style="padding-bottom:18px" align="center">
        <a href="{{ $url }}" style="display:inline-block;background:#0f4c3a;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:11px 24px;border-radius:8px">{{ __('Open the sign-in screen') }}</a>
    </td></tr>
    <tr><td style="font-size:13px;color:#5b6670;line-height:1.6">
        <b>{{ __('This sign-in') }}</b><br>
        @if ($at){{ __('Time') }}: {{ $at }}<br>@endif
        @if (!empty($info['ip'])){{ __('IP address') }}: {{ $info['ip'] }}<br>@endif
        @if (!empty($info['city']) || !empty($info['country'])){{ __('Place') }}: {{ implode(', ', array_filter([$info['city'] ?? null, $info['region'] ?? null, $info['country'] ?? null])) }}<br>@endif
        @if (!empty($info['browser']) || !empty($info['os'])){{ __('Device') }}: {{ implode(' · ', array_filter([$info['browser'] ?? null, $info['os'] ?? null])) }}<br>@endif
    </td></tr>
    <tr><td style="padding-top:16px;font-size:13px;color:#5b6670;line-height:1.6">
        {{ __('The code works only on the screen that asked for it, for :minutes minutes.', ['minutes' => $minutes]) }}
        {{ __('Never tell it to anybody, not even our staff.') }}
        {{ __('If this was not you, do not use it: tell your mill\'s administrator at once.') }}
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
