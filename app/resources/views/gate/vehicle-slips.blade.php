{{-- Every vehicle's complete slip of a day, one vehicle per page. --}}
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@forelse ($slips as $d)
    @include('gate.partials.slip-body', ['d' => $d])
    @if (! $loop->last)<pagebreak />@endif
@empty
    <p>No vehicles on this day.</p>
@endforelse
</body>
</html>
