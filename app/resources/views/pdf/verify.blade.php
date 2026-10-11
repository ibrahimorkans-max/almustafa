{{-- Verification block: QR to the public check page, the code, and how to check by hand. Params: $note (optional). --}}
@if ($verifyUrl)
    <table class="verify box">
        <tr>
            <td style="width: {{ $small ? '22mm' : '26mm' }};">
                <barcode code="{{ $verifyUrl }}" type="QR" size="{{ $small ? '0.62' : '0.75' }}" error="M" disableborder="1" />
            </td>
            <td>
                <div><span class="b">Verification code</span> <span class="mono b" style="font-size: 115%; letter-spacing: 1pt;">{{ $verifyCode }}</span></div>
                <div class="small muted">Scan the code, or open {{ url('/verify') }} and enter {{ $verifyDoc }} with this code. A printout whose figures differ from the page has been altered.</div>
                <div class="small muted"><span class="ur" style="font-size: 120%;">تصدیق کے لیے کوڈ اسکین کریں</span></div>
                @isset($note)
                    <div class="small muted">{{ $note }}</div>
                @endisset
            </td>
        </tr>
    </table>
@endif
