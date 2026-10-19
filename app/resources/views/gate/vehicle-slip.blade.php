{{-- One vehicle's complete slip from the vehicle register. --}}
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('gate.partials.slip-body', ['d' => $d])
</body>
</html>
