{{-- Shared print styles (mPDF subset of CSS). --}}
<style>
    body { font-family: dejavusans; color: #1a1a1a; }
    .ur { font-family: lateef; font-size: 150%; }
    .muted { color: #5b6470; }
    .small { font-size: 85%; }
    .num { text-align: right; font-family: dejavusanscondensed; white-space: nowrap; }
    .mono { font-family: dejavusansmono; }
    .b { font-weight: bold; }
    .c { text-align: center; }
    .r { text-align: right; }
    .green { color: {{ $brand['green'] }}; }
    .gold { color: {{ $brand['gold'] }}; }

    table { border-collapse: collapse; width: 100%; }
    .letterhead td { vertical-align: middle; }
    .letterhead .name { font-size: 17pt; font-weight: bold; color: {{ $brand['green'] }}; }
    .letterhead .name-ur { font-family: lateef; font-size: 17pt; color: {{ $brand['green'] }}; }
    .rule { border-bottom: 1.6pt solid {{ $brand['gold'] }}; height: 1pt; margin: 2pt 0 4pt 0; }

    .titlebar { background-color: {{ $brand['green'] }}; color: #ffffff; margin-top: 3pt; }
    .titlebar td { padding: 4pt 7pt; color: #ffffff; }
    .titlebar .t-en { font-size: 12.5pt; font-weight: bold; letter-spacing: 0.6pt; }
    .titlebar .t-ur { font-family: lateef; font-size: 18pt; text-align: right; }

    .grid { margin-top: 5pt; }
    .grid td, .grid th { border: 0.6pt solid #b8c0c8; padding: 3pt 5pt; vertical-align: top; }
    .grid th { background-color: #eef3ef; text-align: left; font-weight: bold; color: #24342a; }
    .grid .label { background-color: #f6f8f6; color: #3a4a40; }
    .grid .total td { background-color: #eef3ef; font-weight: bold; }
    .grid .grand td { background-color: {{ $brand['green'] }}; color: #ffffff; font-weight: bold; font-size: 110%; }

    .box { border: 0.6pt solid #b8c0c8; padding: 4pt 6pt; margin-top: 5pt; }
    .words { border-left: 2.5pt solid {{ $brand['gold'] }}; background-color: #fdf7ea; padding: 4pt 7pt; margin-top: 5pt; font-weight: bold; }
    .notice { border: 0.8pt solid #c9a227; background-color: #fff8e1; padding: 4pt 6pt; margin-top: 5pt; }
    .flags { color: #9b1c1c; }
    .big { font-size: 125%; font-weight: bold; }

    .sign td { padding-top: 26pt; text-align: center; width: 33%; }
    .sign .line { border-top: 0.6pt solid #555; padding-top: 2pt; font-size: 85%; color: #3a4a40; }
    .verify td { vertical-align: middle; }
</style>
