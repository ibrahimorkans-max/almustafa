{{-- Service usage (API, SMS, WhatsApp, e-mail) for a period: the figures, by mill / provider / module, and the records. --}}
@php($n = fn ($v) => number_format((float) $v, 2))
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'SERVICE USAGE', 'titleUr' => 'سروس کا استعمال', 'small' => false])
<p class="small">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }} (Pakistan time)</p>
<table class="grid">
    <tr><th>API calls</th><th>SMS</th><th>WhatsApp</th><th>E-mails</th><th>Push</th><th>Successful</th><th>Failed</th><th class="num">Estimated</th><th class="num">Charged</th></tr>
    <tr><td>{{ $totals['api'] ?? 0 }}</td><td>{{ $totals['sms'] ?? 0 }}</td><td>{{ $totals['whatsapp'] ?? 0 }}</td><td>{{ $totals['email'] ?? 0 }}</td><td>{{ $totals['push'] ?? 0 }}</td>
        <td>{{ $totals['ok'] ?? 0 }}</td><td>{{ $totals['failed'] ?? 0 }}</td><td class="num">{{ $n($totals['estimated'] ?? 0) }}</td><td class="num">{{ $n($totals['charged'] ?? 0) }}</td></tr>
</table>
@foreach (['By mill' => $byMill, 'By provider' => $byProvider, 'By module' => $byModule] as $heading => $rows)
    <table class="grid" style="margin-top: 8pt;">
        <tr><th>{{ $heading }}</th><th class="num">API</th><th class="num">SMS</th><th class="num">WhatsApp</th><th class="num">E-mail</th><th class="num">Failed</th><th class="num">Estimated</th><th class="num">Charged</th></tr>
        @forelse ($rows as $r)
            <tr><td>{{ $r['name'] }}</td><td class="num">{{ $r['api'] }}</td><td class="num">{{ $r['sms'] }}</td><td class="num">{{ $r['whatsapp'] }}</td><td class="num">{{ $r['email'] }}</td>
                <td class="num">{{ $r['failed'] }}</td><td class="num">{{ $n($r['estimated']) }}</td><td class="num">{{ $n($r['charged']) }}</td></tr>
        @empty
            <tr><td colspan="8" class="muted">None.</td></tr>
        @endforelse
    </table>
@endforeach
<table class="grid small" style="margin-top: 8pt;">
    <tr><th>Usage ID</th><th>When</th><th>Mill</th><th>Service</th><th>Module / why</th><th>By</th><th class="num">Qty</th><th class="num">Cost</th><th>Status</th></tr>
    @foreach ($records as $u)
        <tr><td>{{ $u->reference }}</td><td>{{ $u->occurred_at->timezone('Asia/Karachi')->format('d M H:i:s') }}</td><td>{{ $u->entity?->name ?? '—' }}</td>
            <td>{{ $u->provider }} · {{ $u->service }}</td><td>{{ $u->module }}: {{ $u->description }}</td><td>{{ $u->byLabel() }}</td>
            <td class="num">{{ $u->quantity }}</td><td class="num">{{ $n($u->estimated_cost) }} {{ $u->currency }}</td><td>{{ $u->deliveryStatus() }}</td></tr>
    @endforeach
</table>
</body>
</html>
