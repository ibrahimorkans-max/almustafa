{{--
    The motion language of the sign-in checks (shield panel, 6-box code, "Verifying…", scan line), once per page.
    Why these timings (the psychology of trust):
      - entrances use a fast-out ease (0.16, 1, 0.3, 1): the screen answers at once and then settles — it feels
        instantaneous, never sluggish; exits use a fast-in ease (0.7, 0, 0.84, 0): things leave decisively.
      - micro 120–180 ms for anything under the finger (a digit typed): faster than conscious notice, so input never
        feels delayed; standard 240–320 ms for state changes the eye should follow; ceremonial 600–900 ms only for the
        trust moments (verifying, verified), where a little weight says "something careful happened".
      - nothing bounces (bounce reads as playful, not secure) and nothing blocks input: only transform and opacity
        move, so typing and focus are never held up.
    Everything collapses to plain, instant changes under prefers-reduced-motion.
--}}
@once
    <style>
        :root {
            --rmv-ease-in: cubic-bezier(0.16, 1, 0.3, 1);
            --rmv-ease-out: cubic-bezier(0.7, 0, 0.84, 0);
            --rmv-micro: 160ms;
            --rmv-standard: 280ms;
            --rmv-ceremonial: 720ms;
            --rmv-accent: 79, 125, 255;          /* electric indigo #4f7dff */
            --rmv-ok: 52, 211, 153;
            --rmv-bad: 248, 113, 113;
            --rmv-amber: 251, 191, 36;
        }

        /* ── The panel: dark glass, grain, card entrance (0.96 + blur 8 → 1 + sharp: arrives "in focus") ── */
        .rmv { position: relative; overflow: hidden; border-radius: 1rem; padding: 1.5rem; color: #e6e9f2;
            background: linear-gradient(160deg, rgba(23, 27, 36, .96), rgba(15, 17, 21, .98));
            border: 1px solid rgba(255, 255, 255, .08);
            box-shadow: 0 20px 50px rgba(0, 0, 0, .25), inset 0 1px 0 rgba(255, 255, 255, .05);
            animation: rmv-card-in var(--rmv-ceremonial) var(--rmv-ease-in) both; }
        .rmv::before { content: ""; position: absolute; inset: 0; pointer-events: none; opacity: .07; mix-blend-mode: overlay;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)'/%3E%3C/svg%3E"); }
        .rmv::after { content: ""; position: absolute; inset: -40%; pointer-events: none; opacity: 0;
            background: radial-gradient(circle at 15% 25%, rgba(var(--rmv-accent), .35), transparent 45%); }
        .rmv[data-state=done]::after { animation: rmv-bloom var(--rmv-ceremonial) var(--rmv-ease-in) .25s both; }
        .rmv[data-state=failed] { animation: rmv-card-in var(--rmv-ceremonial) var(--rmv-ease-in) both, rmv-shake 400ms linear .2s 1; }
        .rmv > *:not(.sr-only) { position: relative; }
        /* Children arrive one after another (60 ms): the eye reads top to bottom, as the steps happen. */
        .rmv-top, .rmv-bar, .rmv-pct, .rmv-steps { animation: rmv-fade-up var(--rmv-standard) var(--rmv-ease-in) both; }
        .rmv-bar { animation-delay: 60ms; } .rmv-pct { animation-delay: 90ms; } .rmv-steps { animation-delay: 120ms; }
        .rmv-top { display: flex; gap: 1.25rem; align-items: center; }
        .rmv-title { font-size: 1.15rem; font-weight: 700; line-height: 1.3; color: #fff; }
        .rmv-sub { margin-top: .2rem; font-size: .875rem; color: rgba(230, 233, 242, .72); }

        /* ── The indicator (the trust moment): radar sweep + ripple rings while verifying ── */
        .rmv-shield { position: relative; flex: 0 0 auto; width: 76px; height: 76px; display: grid; place-items: center; }
        .rmv-radar { position: absolute; inset: 4px; border-radius: 9999px; opacity: .9;
            background: conic-gradient(from 0deg, rgba(var(--rmv-accent), 0) 0deg, rgba(var(--rmv-accent), .55) 70deg, rgba(var(--rmv-accent), 0) 90deg);
            -webkit-mask: radial-gradient(circle, transparent 54%, #000 56%); mask: radial-gradient(circle, transparent 54%, #000 56%);
            animation: rmv-rot 1.5s linear infinite; }   /* 1.5 s: slow enough to read as "scanning", fast enough to feel busy */
        .rmv-ring { position: absolute; inset: 8px; border-radius: 9999px; border: 2px solid rgba(var(--rmv-accent), .7); opacity: 0;
            animation: rmv-ripple 2.7s var(--rmv-ease-in) infinite; }   /* one ring every 900 ms: a calm heartbeat, not an alarm */
        .rmv-ring:nth-child(3) { animation-delay: .9s; } .rmv-ring:nth-child(4) { animation-delay: 1.8s; }
        .rmv-shield svg { width: 56px; height: 56px; position: relative; z-index: 1; filter: drop-shadow(0 0 14px rgba(var(--rmv-accent), .45)); }
        .rmv-shield .rmv-icon-ok { filter: drop-shadow(0 0 16px rgba(var(--rmv-ok), .6)); }
        .rmv-shield .rmv-icon-bad { filter: drop-shadow(0 0 14px rgba(var(--rmv-bad), .55)); }
        .rmv-flash { position: absolute; inset: 0; border-radius: 9999px; background: radial-gradient(circle, rgba(var(--rmv-ok), .55), transparent 65%);
            animation: rmv-flash 600ms var(--rmv-ease-in) .35s both; }   /* one bloom: the moment of "yes" */
        .rmv-bad-ring { position: absolute; inset: 6px; border-radius: 9999px; border: 2px solid rgba(var(--rmv-bad), .8);
            animation: rmv-ripple 700ms var(--rmv-ease-in) .2s 1 both; }   /* expands once: firm, not alarming */
        .rmv-check circle { stroke-dasharray: 160; stroke-dashoffset: 160; animation: rmv-draw 400ms var(--rmv-ease-in) forwards; }
        .rmv-check path { stroke-dasharray: 48; stroke-dashoffset: 48; animation: rmv-draw 400ms var(--rmv-ease-in) .2s forwards; }

        /* ── Progress and steps ── */
        .rmv-bar { margin-top: 1.25rem; height: 8px; border-radius: 9999px; background: rgba(255, 255, 255, .08); overflow: hidden; }
        .rmv-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, rgba(var(--rmv-accent), .85), rgb(140, 168, 255), rgba(var(--rmv-accent), .85));
            background-size: 200% 100%; box-shadow: 0 0 12px rgba(var(--rmv-accent), .6);
            animation: rmv-shimmer 1.6s linear infinite; transition: width 900ms var(--rmv-ease-in); }
        .rmv[data-state=done] .rmv-fill { background: rgb(var(--rmv-ok)); box-shadow: 0 0 14px rgba(var(--rmv-ok), .6); animation: none; }
        .rmv[data-state=failed] .rmv-fill { background: rgb(var(--rmv-bad)); animation: none; }
        .rmv-pct { display: flex; justify-content: space-between; margin-top: .35rem; font-size: .75rem; color: rgba(230, 233, 242, .6); }
        .rmv-steps { margin-top: 1.1rem; display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); }
        .rmv-step { display: flex; align-items: center; gap: .55rem; font-size: .825rem; padding: .45rem .6rem; border-radius: .6rem; color: rgba(230, 233, 242, .7);
            transition: background var(--rmv-standard) var(--rmv-ease-in); }
        .rmv-step[data-s=now] { background: rgba(var(--rmv-accent), .14); color: #fff; font-weight: 600; }
        .rmv-step[data-s=done] { color: #fff; }
        .rmv-dot { flex: 0 0 auto; width: 1.6rem; height: 1.6rem; border-radius: 9999px; display: grid; place-items: center; font-size: .75rem; font-weight: 700;
            background: rgba(255, 255, 255, .1); position: relative; }
        .rmv-step[data-s=done] .rmv-dot { background: rgb(var(--rmv-ok)); color: #0f1115; animation: rmv-pop var(--rmv-standard) var(--rmv-ease-in) both; }
        .rmv-step[data-s=now] .rmv-dot { background: rgb(var(--rmv-accent)); color: #fff; }
        .rmv-step[data-s=now] .rmv-dot::after { content: ""; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid rgba(var(--rmv-accent), .8);
            animation: rmv-ripple 1.8s var(--rmv-ease-in) infinite; }

        /* Done: the card leaves upward with a slight tilt as the dashboard takes over. */
        .rmv.rmv-exit { animation: rmv-card-out 400ms var(--rmv-ease-out) forwards; }
        .rmv-enter { animation: rmv-fade-up var(--rmv-standard) var(--rmv-ease-in) both; }

        /* ── 6-box code ── */
        .rmv-otp-row { display: flex; gap: .5rem; direction: ltr; }
        .rmv-otp-cell { position: relative; width: 3rem; height: 3.5rem; perspective: 400px; }
        .rmv-otp-cell input { width: 100%; height: 100%; text-align: center; font-size: 1.5rem; font-weight: 700; border-radius: .7rem;
            border: 1.5px solid rgba(127, 127, 127, .35); background: rgba(127, 127, 127, .06); outline: none; caret-color: rgb(var(--rmv-accent));
            transform-origin: 50% 100%; transition: border-color var(--rmv-micro) var(--rmv-ease-in), box-shadow var(--rmv-micro) var(--rmv-ease-in); }
        .rmv-otp-cell input:focus { border-color: rgb(var(--rmv-accent)); box-shadow: 0 0 0 3px rgba(var(--rmv-accent), .22); }
        .rmv-otp-cell.is-hop input { animation: rmv-hop 180ms var(--rmv-ease-in); border-color: rgb(var(--rmv-accent)); box-shadow: 0 0 14px rgba(var(--rmv-accent), .45); }
        .rmv-otp-row.is-lock .rmv-otp-cell input { animation: rmv-lock 320ms var(--rmv-ease-in); }   /* "all six, sealed" before it is sent */
        .rmv-otp-row.is-error .rmv-otp-cell input { border-color: rgb(var(--rmv-bad)); box-shadow: 0 0 12px rgba(var(--rmv-bad), .45);
            animation: rmv-shake-sm 300ms linear calc(var(--i) * 30ms) both; }   /* a wave left to right: "this one, check again" */
        .rmv-otp-row.is-valid .rmv-otp-cell input { animation: rmv-fold 280ms var(--rmv-ease-out) calc(var(--i) * 40ms) forwards; }
        .rmv-otp-check { position: absolute; inset: 0; display: grid; place-items: center; font-size: 1.4rem; font-weight: 700; color: rgb(var(--rmv-ok));
            opacity: 0; pointer-events: none; }
        .rmv-otp-row.is-valid .rmv-otp-check { animation: rmv-fade-in 200ms var(--rmv-ease-in) calc(var(--i) * 40ms + 200ms) forwards; }

        /* ── "Verifying…" (never blocks typing elsewhere: it shows only while a check is on its way) ── */
        .rmv-busy { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; align-items: center; justify-content: center;
            background: rgba(15, 17, 21, .45); backdrop-filter: blur(4px); animation: rmv-fade-in 180ms var(--rmv-ease-in) both; }
        .rmv-busy-card { display: flex; flex-direction: column; align-items: center; gap: .9rem; padding: 1.6rem 2.2rem; border-radius: 1rem;
            background: rgba(15, 17, 21, .92); color: #e6e9f2; border: 1px solid rgba(255, 255, 255, .08); box-shadow: 0 20px 50px rgba(0, 0, 0, .35);
            animation: rmv-card-in var(--rmv-standard) var(--rmv-ease-in) both; }
        .rmv-spin { width: 54px; height: 54px; border-radius: 9999px; border: 4px solid rgba(255, 255, 255, .1); border-top-color: rgb(var(--rmv-accent));
            box-shadow: 0 0 16px rgba(var(--rmv-accent), .35); animation: rmv-rot .8s linear infinite; }
        .rmv-dots span { display: inline-block; animation: rmv-blink 1.2s infinite; }
        .rmv-dots span:nth-child(2) { animation-delay: .2s; } .rmv-dots span:nth-child(3) { animation-delay: .4s; }

        /* ── The verified ritual: one indigo scan line sweeps the screen as the dashboard opens ── */
        .rmv-scanline { position: fixed; left: 0; right: 0; top: 0; height: 120px; z-index: 70; pointer-events: none;
            background: linear-gradient(180deg, transparent, rgba(var(--rmv-accent), .15) 70%, rgba(var(--rmv-accent), .55) 98%, transparent);
            animation: rmv-scanline 500ms var(--rmv-ease-in) both; }

        @keyframes rmv-card-in { from { opacity: 0; transform: scale(.96); filter: blur(8px); } to { opacity: 1; transform: none; filter: blur(0); } }
        @keyframes rmv-card-out { to { opacity: 0; transform: perspective(800px) rotateX(-8deg) translateY(-20px); } }
        @keyframes rmv-fade-up { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        @keyframes rmv-fade-in { from { opacity: 0; } to { opacity: 1; } }
        @keyframes rmv-ripple { 0% { transform: scale(.8); opacity: .6; } 100% { transform: scale(1.6); opacity: 0; } }
        @keyframes rmv-rot { to { transform: rotate(360deg); } }
        @keyframes rmv-flash { 0% { transform: scale(.4); opacity: 0; } 30% { opacity: 1; } 100% { transform: scale(2.4); opacity: 0; } }
        @keyframes rmv-bloom { 0% { opacity: 0; } 35% { opacity: 1; } 100% { opacity: 0; } }
        @keyframes rmv-draw { to { stroke-dashoffset: 0; } }
        @keyframes rmv-shimmer { to { background-position: -200% 0; } }
        @keyframes rmv-pop { from { transform: scale(.6); } to { transform: scale(1); } }
        @keyframes rmv-shake { 0%, 100% { transform: none; } 16%, 50%, 83% { transform: translateX(-6px); } 33%, 66% { transform: translateX(6px); } }
        @keyframes rmv-shake-sm { 0%, 100% { transform: none; } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
        @keyframes rmv-hop { 0% { transform: scale(1); } 40% { transform: scale(1.08); } 100% { transform: scale(1); } }
        @keyframes rmv-lock { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
        @keyframes rmv-fold { to { transform: rotateX(90deg); opacity: 0; } }
        @keyframes rmv-scanline { from { transform: translateY(-120px); } to { transform: translateY(100vh); } }
        @keyframes rmv-blink { 0%, 80%, 100% { opacity: .2; } 40% { opacity: 1; } }

        /* Reduced motion: no movement at all — states simply appear (the check is drawn, the boxes stay put). */
        @media (prefers-reduced-motion: reduce) {
            .rmv, .rmv *, .rmv::after, .rmv-busy, .rmv-busy-card, .rmv-spin, .rmv-otp-cell input, .rmv-otp-check, .rmv-scanline, .rmv-enter {
                animation: none !important; transition: none !important; }
            .rmv-check path, .rmv-check circle { stroke-dashoffset: 0; }
            .rmv-radar, .rmv-ring, .rmv-flash, .rmv-bad-ring, .rmv-scanline { display: none; }
            .rmv-otp-row.is-valid .rmv-otp-check { opacity: 1; }
            .rmv-otp-row.is-valid .rmv-otp-cell input { opacity: 0; }
        }
    </style>
@endonce
