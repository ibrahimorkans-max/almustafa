{{-- Signature boxes. Param: $signs = [[English, Urdu, name?], ...]; a known name is printed above the line. --}}
<table class="sign">
    <tr>
        @foreach ($signs as $sign)
            <td style="width: {{ floor(100 / count($signs)) }}%;">
                @if (! empty($sign[2]))
                    <div style="font-size: 7.5pt; text-align: center; color: #374151;">{{ $sign[2] }}</div>
                @endif
                <div class="line">{{ $sign[0] }} · <span class="ur" style="font-size: 115%;">{{ $sign[1] }}</span></div>
            </td>
        @endforeach
    </tr>
</table>
