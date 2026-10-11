{{-- Signature boxes. Param: $signs = [[English, Urdu], ...] --}}
<table class="sign">
    <tr>
        @foreach ($signs as [$en, $ur])
            <td style="width: {{ floor(100 / count($signs)) }}%;"><div class="line">{{ $en }} · <span class="ur" style="font-size: 115%;">{{ $ur }}</span></div></td>
        @endforeach
    </tr>
</table>
