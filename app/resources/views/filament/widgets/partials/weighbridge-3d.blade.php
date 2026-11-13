{{--
    The 3D weighbridge (public/js/weighbridge-3d.js), the Live weight screen's view of the weighbridge. LIVE shows this
    weighbridge's own reading (the readout and the platform display) and the vehicle typed in the form beside it;
    SIMULATION is a training model of the yard whose weights are never real. It runs in the browser only (wire:ignore),
    so the weighing form is never interrupted. $s: the weighbridge shown (LiveWeightBoard::currentScale()).
--}}
@php
    $wbMill = \App\Models\Entity::query()->find(app(\App\Domain\Tenancy\TenantContext::class)->entityId())?->name ?? 'Rice Mill';
    $wbKg = $s['kg'];
    $wbUnit = (float) $s['capacity'] >= 1000 ? 'TONNES' : 'KG';
@endphp
<section class="mnx-wb3d" data-wb3d-root data-mode="live"
    data-mill="{{ $wbMill }}"
    data-wb3d-live="{{ route('filament.admin.gate.live-weight', ['scale' => $s['scale_id']]) }}"
    data-scale-code="{{ $s['code'] }}" data-capacity="{{ $s['capacity'] }}">
    <style>
        .mnx-wb3d { position: relative; border-radius: 14px; overflow: hidden; background: #0d1620; color: #e6edf3; box-shadow: 0 6px 24px rgba(0,0,0,.18); height: 100%; min-height: 460px; }
        .mnx-wb3d [data-wb3d="canvas"] { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: .85rem; color: #94a3b8; }
        .mnx-wb3d [data-wb3d="canvas"] canvas { width: 100% !important; height: 100% !important; display: block; }
        .mnx-wb3d .top { position: absolute; top: .6rem; left: .6rem; right: .6rem; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: .5rem; pointer-events: none; }
        .mnx-wb3d .top > * { pointer-events: auto; }
        .mnx-wb3d .seg { display: inline-flex; border: 1px solid rgba(255,255,255,.25); border-radius: 9999px; overflow: hidden; background: rgba(13,22,32,.7); }
        .mnx-wb3d .seg button { padding: .3rem .8rem; font-size: .72rem; font-weight: 800; color: #cbd5e1; background: none; border: 0; cursor: pointer; letter-spacing: .04em; }
        .mnx-wb3d .seg button[aria-pressed="true"] { background: #ffd34d; color: #111; }
        .mnx-wb3d .badge { margin-top: .35rem; display: inline-block; padding: .2rem .55rem; border-radius: 6px; font-size: .68rem; font-weight: 800; letter-spacing: .05em; }
        .mnx-wb3d[data-mode="sim"] .badge { background: #f59e0b; color: #111; }
        .mnx-wb3d[data-mode="live"] .badge { background: #16a34a; color: #fff; }
        .mnx-wb3d[data-mode="sim"] .badge::after { content: 'SIMULATION — training model, weights are not real'; }
        .mnx-wb3d[data-mode="live"] .badge::after { content: 'LIVE — this weighbridge\'s own reading'; }
        /* The weight readout: the weighbridge's reading, large. */
        .mnx-wb3d .hud { min-width: 230px; padding: .55rem .8rem; border-radius: 12px; background: rgba(5,8,5,.82); border: 1px solid rgba(255,255,255,.12); text-align: right; font-family: ui-monospace, "Courier New", monospace; }
        .mnx-wb3d .hud .code { font-size: .68rem; color: #9fe8ff; letter-spacing: .08em; }
        .mnx-wb3d .hud .kg { font-size: 2.3rem; font-weight: 800; line-height: 1.05; color: #ff4a2f; text-shadow: 0 0 14px rgba(255,60,20,.55); }
        .mnx-wb3d .hud .kg small { font-size: .9rem; color: #ffb4a6; margin-left: .25rem; }
        .mnx-wb3d .hud .sub { display: flex; justify-content: space-between; gap: .8rem; font-size: .72rem; color: #cbd5e1; }
        .mnx-wb3d .hud [data-hud="state"] { font-weight: 800; }
        .mnx-wb3d .hud [data-hud="state"].stable { color: #3dff7a; }
        .mnx-wb3d .hud [data-hud="state"].moving { color: #ffd84d; }
        .mnx-wb3d .hud [data-hud="state"].none { color: #ff6b6b; }
        .mnx-wb3d .hud [data-hud="plate"] { font-size: .72rem; color: #ffd34d; margin-top: .15rem; }
        .mnx-wb3d .cams { position: absolute; bottom: .6rem; left: .6rem; right: .6rem; display: flex; flex-wrap: wrap; gap: .3rem; }
        .mnx-wb3d .btn { padding: .32rem .65rem; font-size: .7rem; font-weight: 700; border-radius: 7px; border: 1px solid rgba(255,255,255,.18); background: rgba(13,22,32,.78); color: #e6edf3; cursor: pointer; letter-spacing: .02em; }
        .mnx-wb3d .btn:hover { border-color: #ffd34d; }
        .mnx-wb3d .btn[aria-pressed="true"] { background: #ffd34d; color: #111; border-color: #ffd34d; }
        .mnx-wb3d .btn.go { background: #16a34a; border-color: #16a34a; }
        .mnx-wb3d .btn.stop { background: #b91c1c; border-color: #b91c1c; }
        /* Simulation drawer (left). */
        .mnx-wb3d .drawer { position: absolute; top: 3.6rem; left: .6rem; bottom: 3.4rem; width: min(300px, calc(100% - 1.2rem)); overflow-y: auto; padding: .7rem; border-radius: 12px; background: rgba(13,22,32,.9); border: 1px solid rgba(255,255,255,.1); display: flex; flex-direction: column; gap: .6rem; }
        .mnx-wb3d .stats { display: grid; grid-template-columns: 1fr 1fr; gap: .35rem; }
        .mnx-wb3d .stat { background: rgba(255,255,255,.05); border-radius: 8px; padding: .3rem .45rem; }
        .mnx-wb3d .stat span { display: block; font-size: .58rem; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; }
        .mnx-wb3d .stat b { font-size: .78rem; font-family: ui-monospace, monospace; }
        .mnx-wb3d .tools { display: flex; flex-wrap: wrap; gap: .3rem; }
        .mnx-wb3d h3 { font-size: .64rem; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; margin-bottom: .25rem; }
        .mnx-wb3d ol { font-size: .66rem; font-family: ui-monospace, monospace; color: #cbd5e1; list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: .12rem; }
        .mnx-wb3d table { width: 100%; font-size: .62rem; font-family: ui-monospace, monospace; border-collapse: collapse; }
        .mnx-wb3d td, .mnx-wb3d th { padding: .12rem .18rem; border-bottom: 1px solid rgba(255,255,255,.06); text-align: left; }
        .mnx-wb3d[data-mode="live"] .sim-only { display: none !important; }
        .mnx-wb3d [data-wb3d="toast"] { position: absolute; top: 3.4rem; left: 50%; transform: translateX(-50%); background: #b91c1c; color: #fff; padding: .45rem .8rem; border-radius: 8px; font-size: .8rem; font-weight: 600; }
        .mnx-wb3d [data-wb3d="recorded"] { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%); background: rgba(22,163,74,.92); color: #fff; padding: .7rem 1.2rem; border-radius: 12px; font-size: 1.05rem; font-weight: 800; text-align: center; box-shadow: 0 8px 30px rgba(0,0,0,.35); }
        .mnx-wb3d [data-wb3d="title"] { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; background: rgba(5,10,16,.55); pointer-events: none; }
        .mnx-wb3d [data-wb3d="title"] strong { font-size: clamp(1.4rem, 4vw, 2.6rem); color: #ffd34d; letter-spacing: .05em; }
        .mnx-wb3d [data-wb3d="title"] span { font-size: clamp(.9rem, 2vw, 1.3rem); color: #fff; font-weight: 700; }
        .mnx-wb3d [data-wb3d="title"] em { font-size: .8rem; color: #cbd5e1; font-style: normal; margin-top: .4rem; }
        .mnx-wb3d [data-wb3d="slip"] { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,.55); }
        .mnx-wb3d [data-wb3d="slip"] > div { background: #fff; color: #111; border-radius: 10px; padding: 1rem 1.2rem; max-width: 95%; }
        .mnx-wb3d [data-wb3d="slip"] pre { font-size: .8rem; white-space: pre-wrap; margin: 0 0 .8rem; }
        .mnx-wb3d [hidden] { display: none !important; }
        .mnx-wb3d .note { font-size: .64rem; color: #94a3b8; line-height: 1.4; }
        @media print { .mnx-wb3d { display: none !important; } }
    </style>

    <div data-wb3d="canvas">Loading the 3D view…</div>

    <div class="top">
        <div>
            <div class="seg" role="group" aria-label="Live or simulation">
                <button type="button" data-wb3d-mode="live" aria-pressed="true">LIVE</button>
                <button type="button" data-wb3d-mode="sim" aria-pressed="false">SIMULATION</button>
            </div>
            <div><span class="badge"></span> <button type="button" class="btn sim-only" data-wb3d-action="drawer" aria-pressed="true" style="margin-top:.35rem">CONTROLS</button></div>
        </div>
        <div class="hud" aria-live="polite">
            <div class="code">{{ $s['code'] }} · {{ $s['name'] }} · {{ $wbUnit }}</div>
            <div class="kg"><span data-hud="kg">{{ $wbKg === null ? '— — —' : number_format((float) $wbKg, 0) }}</span><small>kg</small></div>
            <div class="sub">
                <span data-hud="maund">{{ $wbKg === null ? 'no reading yet' : number_format((float) $wbKg / 40, 2).' maund' }}</span>
                <span data-hud="state" @class(['stable' => $s['live'] && $s['stable'], 'moving' => $s['live'] && ! $s['stable'], 'none' => ! $s['live']])>{{ ! $s['live'] ? 'NO SIGNAL' : ($s['stable'] ? 'STABLE' : 'MOVING') }}</span>
            </div>
            <div data-hud="plate"></div>
        </div>
    </div>

    <div class="drawer sim-only">
        <div class="stats">
            <div class="stat"><span>On the weighbridge</span><b data-wb3d-stat="vehicle">—</b></div>
            <div class="stat"><span>Weighbridge</span><b data-wb3d-stat="wb">—</b></div>
            <div class="stat"><span>Gross</span><b data-wb3d-stat="gross">—</b></div>
            <div class="stat"><span>Tare</span><b data-wb3d-stat="tare">—</b></div>
            <div class="stat"><span>Net</span><b data-wb3d-stat="net">—</b></div>
            <div class="stat"><span>Completed</span><b data-wb3d-stat="done">0</b></div>
            <div class="stat"><span>Inside the mill</span><b data-wb3d-stat="inside">0</b></div>
            <div class="stat"><span>Waiting at the gate</span><b data-wb3d-stat="entryWait">0</b></div>
            <div class="stat"><span>Waiting to weigh</span><b data-wb3d-stat="wbWait">0</b></div>
            <div class="stat"><span>Gates (in / out)</span><b><span data-wb3d-stat="gateIn">CLOSED</span> / <span data-wb3d-stat="gateOut">CLOSED</span></b></div>
            <div class="stat" style="display:none"><b data-wb3d-stat="weight"></b></div>
        </div>
        <div class="tools">
            <button type="button" class="btn go" data-wb3d-action="start">START</button>
            <button type="button" class="btn" data-wb3d-action="pause">PAUSE</button>
            <button type="button" class="btn stop" data-wb3d-action="reset">RESET</button>
            <button type="button" class="btn" data-wb3d-action="demo">DEMO TOUR</button>
            <button type="button" class="btn" data-wb3d-action="tractor">+ TRACTOR TROLLEY</button>
            <button type="button" class="btn" data-wb3d-action="container">+ CONTAINER</button>
            <button type="button" class="btn" data-wb3d-action="truck">+ TRUCK</button>
            <button type="button" class="btn" data-wb3d-action="open-in">OPEN ENTRY GATE</button>
            <button type="button" class="btn" data-wb3d-action="close-in">CLOSE ENTRY GATE</button>
            <button type="button" class="btn" data-wb3d-action="open-out">OPEN EXIT GATE</button>
            <button type="button" class="btn" data-wb3d-action="close-out">CLOSE EXIT GATE</button>
            <button type="button" class="btn" data-wb3d-action="auto">AUTO MODE</button>
            <button type="button" class="btn" data-wb3d-action="slip">WEIGHT SLIP</button>
        </div>
        <p class="note">Weights here come from each model vehicle's empty weight and load, shared over its axles; they are never recorded.</p>
        <div><h3>Completed weighments</h3><table><thead><tr><th>Slip</th><th>Vehicle</th><th>Type</th><th>Gross</th><th>Tare</th><th>Net</th></tr></thead><tbody data-wb3d="history"></tbody></table></div>
        <div><h3>Entry and exit log</h3><ol data-wb3d="log"></ol></div>
    </div>

    <div data-wb3d="toast" hidden></div>
    <div data-wb3d="recorded" hidden></div>
    <div data-wb3d="title" hidden>
        <strong>{{ mb_strtoupper($wbMill) }}</strong>
        <span>SMART AUTOMATED WEIGHBRIDGE SYSTEM</span>
        <em>REAL-TIME VEHICLE TRACKING | AUTOMATIC GATE CONTROL | DIGITAL WEIGHMENT</em>
    </div>
    <div data-wb3d="slip" hidden>
        <div>
            <pre data-slip="body"></pre>
            <button type="button" class="btn" style="background:#111" data-wb3d-action="print-slip">Print</button>
            <button type="button" class="btn" style="background:#555" data-wb3d-action="close-slip">Close</button>
        </div>
    </div>
    <div class="cams" role="group" aria-label="Camera">
        <button type="button" class="btn" data-wb3d-cam="side">WEIGHBRIDGE</button>
        <button type="button" class="btn" data-wb3d-cam="cab">DRIVER VIEW</button>
        <button type="button" class="btn" data-wb3d-cam="top">TOP VIEW</button>
        <button type="button" class="btn" data-wb3d-cam="operator">OPERATOR DISPLAY</button>
        <button type="button" class="btn" data-wb3d-cam="gate">MAIN GATE</button>
        <button type="button" class="btn" data-wb3d-cam="aerial">AERIAL</button>
        <button type="button" class="btn sim-only" data-wb3d-cam="follow">FOLLOW VEHICLE</button>
        <button type="button" class="btn" data-wb3d-action="night" aria-pressed="false">NIGHT</button>
    </div>

    <script type="importmap">{ "imports": { "three": "{{ asset('js/vendor/three-0.169.0.module.min.js') }}" } }</script>
    <script type="module" src="{{ asset('js/weighbridge-3d.js') }}?v=8"></script>
</section>
