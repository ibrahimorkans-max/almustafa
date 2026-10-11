@include('pdf.styles')
<div @if ($p->rtl()) dir="rtl" @endif>
    @include('documents.partials.body')
</div>
